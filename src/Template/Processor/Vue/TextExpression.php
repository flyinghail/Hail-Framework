<?php

namespace Hail\Template\Processor\Vue;

use Hail\Template\Expression\Expression;
use Hail\Template\Processor\Processor;
use Hail\Template\Tokenizer\Token\Text;
use Hail\Template\Tokenizer\Token\TokenInterface;

final class TextExpression extends Processor
{
    public static function process(TokenInterface $element): bool
    {
        if (!$element instanceof Text) {
            return false;
        }

        $text = $element->getValue();

        $regex = '/\{\{(?P<expression>.*?)\}\}/x';
        \preg_match_all($regex, $text, $matches);

        if ($matches['expression'] !== []) {
            foreach ($matches['expression'] as $index => $expression) {
                $value = Expression::parseWithFilters($expression);

                $text = \str_replace($matches[0][$index], $value, $text);
            }

            $element->setValue($text);
        }

        return false;
    }
}