<?php
/**
 *
 * This file is part of Aura for PHP.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 *
 */

namespace Hail\Session;

/**
 * A session segment; lazy-loads from the session.
 *
 * @package Aura.Session
 *
 */
class Segment
{
    /**
     * The session manager.
     *
     * @var Session
     *
     */
    protected $session;

    /**
     * The segment name.
     *
     * @var string
     *
     */
    protected $name;

    /**
     * Constructor.
     *
     * @param Session $session The session manager.
     *
     * @param string  $name    The segment name.
     *
     */
    public function __construct(Session $session, $name)
    {
        $this->session = $session;
        $this->name = $name;
    }

    /**
     * Returns the value of a key in the segment.
     *
     * @param string $key The key in the segment.
     *
     * @param mixed  $alt An alternative value to return if the key is not set.
     *
     * @return mixed
     *
     */
    public function get($key, $alt = null)
    {
        $this->resumeSession();

        return $_SESSION[$this->name][$key] ?? $alt;
    }

    /**
     * Returns the entire segment.
     *
     * @return mixed
     *
     */
    public function getSegment()
    {
        $this->resumeSession();

        return $_SESSION[$this->name] ?? null;
    }

    /**
     * Sets the value of a key in the segment.
     *
     * @param string $key The key to set.
     *
     * @param mixed  $val The value to set it to.
     *
     */
    public function set($key, $val)
    {
        $this->resumeOrStartSession();
        $_SESSION[$this->name][$key] = $val;
    }

    /**
     * Append a value to a numeric key in the segment.
     *
     * @param mixed $val The value to append.
     *
     */
    public function add($val)
    {
        $this->resumeOrStartSession();
        $_SESSION[$this->name][] = $val;
    }

    /**
     * Clear all data from the segment.
     */
    public function clear()
    {
        if ($this->resumeSession()) {
            $_SESSION[$this->name] = [];
        }
    }


    /**
     * Remove a key from the segment, or remove the entire segment (including key) from the session
     *
     * @param null $key
     */
    public function remove($key = null)
    {
        if ($this->resumeSession()) {
            if ($key) {
                if (isset($_SESSION[$this->name]) && \array_key_exists($key, $_SESSION[$this->name])) {
                    unset($_SESSION[$this->name][$key]);
                }
            } else {
                unset($_SESSION[$this->name]);
            }
        }
    }

    /**
     * Sets a flash value for the *next* request.
     *
     * @param string $key The key for the flash value.
     *
     * @param mixed  $val The flash value itself.
     *
     */
    public function setFlash($key, $val)
    {
        $this->resumeOrStartSession();
        $_SESSION[Session::FLASH_NEXT][$this->name][$key] = $val;
    }

    /**
     * Append a flash value with a numeric key for the *next* request.
     *
     * @param mixed $val The flash value itself.
     *
     */
    public function addFlash($val)
    {
        $this->resumeOrStartSession();
        $_SESSION[Session::FLASH_NEXT][$this->name][] = $val;
    }

    /**
     * Gets the flash value for a key in the *current* request.
     *
     * @param string $key The key for the flash value.
     *
     * @param mixed  $alt An alternative value to return if the key is not set.
     *
     * @return mixed The flash value itself.
     *
     */
    public function getFlash($key, $alt = null)
    {
        $this->resumeSession();

        return $_SESSION[Session::FLASH_NOW][$this->name][$key] ?? $alt;
    }

    /**
     * Gets all the flash values in the *current* request.
     *
     * @param mixed $alt An alternative value to return if no flash values are set.
     *
     * @return mixed The flash values themselves.
     *
     */
    public function getAllCurrentFlash($alt = [])
    {
        $this->resumeSession();

        return $_SESSION[Session::FLASH_NOW][$this->name] ?? $alt;
    }

    /**
     * Clears flash values for *only* the next request.
     *
     */
    public function clearFlash()
    {
        if ($this->resumeSession()) {
            $_SESSION[Session::FLASH_NEXT][$this->name] = [];
        }
    }

    /**
     * Gets the flash value for a key in the *next* request.
     *
     * @param string $key The key for the flash value.
     *
     * @param mixed  $alt An alternative value to return if the key is not set.
     *
     * @return mixed The flash value itself.
     *
     */
    public function getFlashNext($key, $alt = null)
    {
        $this->resumeSession();

        return $_SESSION[Session::FLASH_NEXT][$this->name][$key] ?? $alt;
    }

    /**
     * Gets all flash values for the *next* request.
     *
     * @param mixed $alt An alternative value to return if no flash values set.
     *
     * @return mixed The flash values themselves.
     *
     */
    public function getAllFlashNext($alt = [])
    {
        $this->resumeSession();

        return $_SESSION[Session::FLASH_NEXT][$this->name] ?? $alt;
    }

    /**
     * Sets a flash value for the *next* request *and* the current one.
     *
     * @param string $key The key for the flash value.
     *
     * @param mixed  $val The flash value itself.
     *
     */
    public function setFlashNow($key, $val)
    {
        $this->resumeOrStartSession();
        $_SESSION[Session::FLASH_NOW][$this->name][$key] = $val;
        $_SESSION[Session::FLASH_NEXT][$this->name][$key] = $val;
    }

    /**
     * Append a flash value with a numeric key for the *next* request *and* the current one.
     *
     * @param mixed $val The flash value itself.
     *
     */
    public function addFlashNow($val)
    {
        $this->resumeOrStartSession();
        $_SESSION[Session::FLASH_NOW][$this->name][] = $val;
        $_SESSION[Session::FLASH_NEXT][$this->name][] = $val;
    }

    /**
     * Clears flash values for *both* the next request *and* the current one.
     */
    public function clearFlashNow()
    {
        if ($this->resumeSession()) {
            $_SESSION[Session::FLASH_NOW][$this->name] = [];
            $_SESSION[Session::FLASH_NEXT][$this->name] = [];
        }
    }

    /**
     * Retains all the current flash values for the next request; values that
     * already exist for the next request take precedence.
     */
    public function keepFlash()
    {
        if ($this->resumeSession()) {
            $_SESSION[Session::FLASH_NEXT][$this->name] = array_merge(
                $_SESSION[Session::FLASH_NEXT][$this->name],
                $_SESSION[Session::FLASH_NOW][$this->name]
            );
        }
    }

    /**
     * Loads the segment only if the session has already been started, or if
     * a session is available (in which case it resumes the session first).
     *
     * @return bool
     *
     */
    protected function resumeSession()
    {
        if ($this->session->isStarted() || $this->session->resume()) {
            $this->load();

            return true;
        }

        return false;
    }

    /**
     * Sets the segment properties to $_SESSION references.
     *
     */
    protected function load()
    {
        if (!isset($_SESSION[$this->name])) {
            $_SESSION[$this->name] = [];
        }

        if (!isset($_SESSION[Session::FLASH_NOW][$this->name])) {
            $_SESSION[Session::FLASH_NOW][$this->name] = [];
        }

        if (!isset($_SESSION[Session::FLASH_NEXT][$this->name])) {
            $_SESSION[Session::FLASH_NEXT][$this->name] = [];
        }
    }

    /**
     * Resumes a previous session, or starts a new one, and loads the segment.
     *
     * @return null
     *
     */
    protected function resumeOrStartSession()
    {
        if (!$this->resumeSession()) {
            $this->session->start();
            $this->load();
        }
    }
}