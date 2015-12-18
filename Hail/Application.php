<?php
/**
 * Created by IntelliJ IDEA.
 * User: FlyingHail
 * Date: 2015/7/19 0019
 * Time: 22:14
 */

namespace Hail;

use Hail\Exception\Application as ApplicationException;
use Hail\Exception\BadRequest;
use Hail\Tracy\Debugger;

/**
 * Front Controller.
 *
 */
class Application
{
	use DITrait;
	private $dispatcher = [];

	public function run()
	{
		try {
			$this->event->emit('startup');
			$this->process();
		} catch (\Exception $e) {
			$this->event->emit('error', $e);
			$this->processException($e);
		} finally {
			$this->event->emit('shutdown');
		}
	}

	private function process()
	{
		$result = $this->router->dispatch(
			$this->request->getMethod(),
			$this->request->getPathInfo()
		);

		if (isset($result['error'])) {
			throw new BadRequest('Router Error', $result['error']);
		}

		$app = $result['handler']['app'] ?? '';
		$controller = $result['handler']['controller'] ?? '';
		$action= $result['handler']['action'] ?? '';

		$dispatcher = $this->getDispatcher($app);
		$dispatcher->run($controller, $action, $result['params']);
	}

	/**
	 * @param string $app
	 * @return Dispatcher
	 * @throws BadRequest
	 */
	private function getDispatcher($app)
	{
		if (!isset($this->dispatcher[$app])) {
			return $this->dispatcher[$app] = new Dispatcher($app);
		}
		return $this->dispatcher[$app];
	}

	public function processException(\Exception $e)
	{
		$debuggerEnabled = Debugger::isEnabled();
		if ($debuggerEnabled && !$e instanceof ApplicationException) {
			throw $e;
		}

		if (!$e instanceof BadRequest) {
			$this->response->warnOnBuffer = FALSE;
		}

		$code = $e instanceof BadRequest ? ($e->getCode() ?: 404) : 500;
		if (!$this->response->isSent()) {
			$this->response->setCode($code);
		}

		if (!$debuggerEnabled) {
			$msg = [
				403 => 'Access Denied',
				404 => 'Not Found',
				405 => 'Method Not Allowed',
				410 => 'Gone',
				500 => 'Server Error'
			];

			$msg = $msg[$code] ?? $e->getMessage();
		} else {
			$msg = $e->getMessage();
		}

		$this->output->json->send([
			'ret' => $code,
			'msg' => $msg
		]);

		!$debuggerEnabled && Debugger::log($e, Debugger::EXCEPTION);
	}
}