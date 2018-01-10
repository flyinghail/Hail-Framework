<?php

namespace Hail\I18n\Gettext\Languages;

use Hail\Util\Strings;


/**
 * Main class to convert the plural rules of a language from CLDR to gettext.
 */
class Language
{
    /**
     * The language ID.
     *
     * @var string
     */
    public $id;
    /**
     * The language name.
     *
     * @var string
     */
    public $name;
    /**
     * If this language is deprecated: the gettext code of the new language.
     *
     * @var null|string
     */
    public $supersededBy;
    /**
     * The script name.
     *
     * @var string|null
     */
    public $script;
    /**
     * The territory name.
     *
     * @var string|null
     */
    public $territory;
    /**
     * The name of the base language
     *
     * @var string|null
     */
    public $baseLanguage;
    /**
     * The list of categories.
     *
     * @var Category[]
     */
    public $categories;
    /**
     * The gettext formula to decide which category should be applied.
     *
     * @var string
     */
    public $formula;

    /**
     * Initialize the instance and parse the language code.
     *
     * @param array $info The result of CldrData::getLanguageInfo()
     *
     * @throws \RuntimeException Throws an Exception if $fullId is not valid.
     * @throws \InvalidArgumentException
     */
    private function __construct($info)
    {
        $this->id = $info['id'];
        $this->name = $info['name'];
        $this->supersededBy = $info['supersededBy'] ?? null;
        $this->script = $info['script'] ?? null;
        $this->territory = $info['territory'] ?? null;
        $this->baseLanguage = $info['baseLanguage'] ?? null;
        // Let's build the category list
        $this->categories = [];
        foreach ($info['categories'] as $cldrCategoryId => $cldrFormulaAndExamples) {
            $category = new Category($cldrCategoryId, $cldrFormulaAndExamples);
            foreach ($this->categories as $c) {
                if ($category->id === $c->id) {
                    throw new \RuntimeException("The category '{$category->id}' is specified more than once");
                }
            }
            $this->categories[] = $category;
        }

        if (empty($this->categories)) {
            throw new \RuntimeException("The language '{$info['id']}' does not have any plural category");
        }

        // Let's sort the categories from 'zero' to 'other'
        \usort($this->categories, function (Category $category1, Category $category2) {
            return \array_search($category1->id, CldrData::$categories, true) -
                \array_search($category2->id, CldrData::$categories, true);
        });

        // The 'other' category should always be there
        if ($this->categories[\count($this->categories) - 1]->id !== CldrData::OTHER_CATEGORY) {
            throw new \RuntimeException("The language '{$info['id']}' does not have the '" . CldrData::OTHER_CATEGORY . "' plural category");
        }

        $this->checkAlwaysTrueCategories();
        $this->checkAlwaysFalseCategories();
        $this->checkAllCategoriesWithExamples();
        $this->formula = $this->buildFormula();
    }

    /**
     * Return a list of all languages available.
     *
     * @return Language[]
     */
    public static function getAll()
    {
        $result = [];
        foreach (\array_keys(CldrData::getLanguageNames()) as $cldrLanguageId) {
            $result[] = new Language(CldrData::getLanguageInfo($cldrLanguageId));
        }

        return $result;
    }

    /**
     * Return a Language instance given the language id
     *
     * @param string $id
     *
     * @return Language|null
     */
    public static function getById($id)
    {
        $result = null;
        $info = CldrData::getLanguageInfo($id);
        if (isset($info)) {
            $result = new Language($info);
        }

        return $result;
    }

    /**
     * Let's look for categories that will always occur.
     * This because with decimals (CLDR) we may have more cases, with integers (gettext) we have just one case.
     * If we found that (single) category we reduce the categories to that one only.
     *
     * @throws \RuntimeException
     */
    private function checkAlwaysTrueCategories()
    {
        $alwaysTrueCategory = null;
        foreach ($this->categories as $category) {
            if ($category->formula === true) {
                if (null === $category->examples) {
                    throw new \RuntimeException("The category '{$category->id}' should always occur, but it does not have examples (so for CLDR it will never occur for integers!)");
                }
                $alwaysTrueCategory = $category;
                break;
            }
        }
        if (null !== $alwaysTrueCategory) {
            foreach ($this->categories as $category) {
                if (($category !== $alwaysTrueCategory) && null !== $category->examples) {
                    throw new \RuntimeException("The category '{$category->id}' should never occur, but it has some examples (so for CLDR it will occur!)");
                }
            }
            $alwaysTrueCategory->id = CldrData::OTHER_CATEGORY;
            $alwaysTrueCategory->formula = null;
            $this->categories = [$alwaysTrueCategory];
        }
    }

    /**
     * Let's look for categories that will never occur.
     * This because with decimals (CLDR) we may have more cases, with integers (gettext) we have some less cases.
     * If we found those categories we strip them out.
     *
     * @throws \RuntimeException
     */
    private function checkAlwaysFalseCategories()
    {
        $filtered = [];
        foreach ($this->categories as $category) {
            if ($category->formula === false) {
                if (null !== $category->examples) {
                    throw new \RuntimeException("The category '{$category->id}' should never occur, but it has examples (so for CLDR it may occur!)");
                }
            } else {
                $filtered[] = $category;
            }
        }
        $this->categories = $filtered;
    }

    /**
     * Let's look for categories that don't have examples.
     * This because with decimals (CLDR) we may have more cases, with integers (gettext) we have some less cases.
     * If we found those categories, we check that they never occur and we strip them out.
     *
     * @throws \RuntimeException
     */
    private function checkAllCategoriesWithExamples()
    {
        $allCategoriesIds = [];
        $goodCategories = [];
        $badCategories = [];
        $badCategoriesIds = [];
        foreach ($this->categories as $category) {
            $allCategoriesIds[] = $category->id;
            if (null !== $category->examples) {
                $goodCategories[] = $category;
            } else {
                $badCategories[] = $category;
                $badCategoriesIds[] = $category->id;
            }
        }

        if (empty($badCategories)) {
            return;
        }

        $removeCategoriesWithoutExamples = false;
        switch (\implode(',', $badCategoriesIds) . '@' . \implode(',', $allCategoriesIds)) {
            case CldrData::OTHER_CATEGORY . '@one,few,many,' . CldrData::OTHER_CATEGORY:
                switch ($this->buildFormula()) {
                    case '(n % 10 == 1 && n % 100 != 11) ? 0 : ((n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14)) ? 1 : ((n % 10 == 0 || n % 10 >= 5 && n % 10 <= 9 || n % 100 >= 11 && n % 100 <= 14) ? 2 : 3))':
                        // Numbers ending with 0                 => case 2 ('many')
                        // Numbers ending with 1 but not with 11 => case 0 ('one')
                        // Numbers ending with 11                => case 2 ('many')
                        // Numbers ending with 2 but not with 12 => case 1 ('few')
                        // Numbers ending with 12                => case 2 ('many')
                        // Numbers ending with 3 but not with 13 => case 1 ('few')
                        // Numbers ending with 13                => case 2 ('many')
                        // Numbers ending with 4 but not with 14 => case 1 ('few')
                        // Numbers ending with 14                => case 2 ('many')
                        // Numbers ending with 5                 => case 2 ('many')
                        // Numbers ending with 6                 => case 2 ('many')
                        // Numbers ending with 7                 => case 2 ('many')
                        // Numbers ending with 8                 => case 2 ('many')
                        // Numbers ending with 9                 => case 2 ('many')
                        // => the 'other' case never occurs: use 'other' for 'many'
                        $removeCategoriesWithoutExamples = true;
                        break;
                    case '(n == 1) ? 0 : ((n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14)) ? 1 : ((n != 1 && (n % 10 == 0 || n % 10 == 1) || n % 10 >= 5 && n % 10 <= 9 || n % 100 >= 12 && n % 100 <= 14) ? 2 : 3))':
                        // Numbers ending with 0                  => case 2 ('many')
                        // Numbers ending with 1 but not number 1 => case 2 ('many')
                        // Number 1                               => case 0 ('one')
                        // Numbers ending with 2 but not with 12  => case 1 ('few')
                        // Numbers ending with 12                 => case 2 ('many')
                        // Numbers ending with 3 but not with 13  => case 1 ('few')
                        // Numbers ending with 13                 => case 2 ('many')
                        // Numbers ending with 4 but not with 14  => case 1 ('few')
                        // Numbers ending with 14                 => case 2 ('many')
                        // Numbers ending with 5                  => case 2 ('many')
                        // Numbers ending with 6                  => case 2 ('many')
                        // Numbers ending with 7                  => case 2 ('many')
                        // Numbers ending with 8                  => case 2 ('many')
                        // Numbers ending with 9                  => case 2 ('many')
                        // => the 'other' case never occurs: use 'other' for 'many'
                        $removeCategoriesWithoutExamples = true;
                        break;
                }
        }
        if (!$removeCategoriesWithoutExamples) {
            throw new \RuntimeException("Unhandled case of plural categories without examples '" . \implode(', ',
                    $badCategoriesIds) . "' out of '" . \implode(', ', $allCategoriesIds) . "'");
        }
        if ($badCategories[\count($badCategories) - 1]->id === CldrData::OTHER_CATEGORY) {
            // We're removing the 'other' cagory: let's change the last good category to 'other'
            $lastGood = $goodCategories[\count($goodCategories) - 1];
            $lastGood->id = CldrData::OTHER_CATEGORY;
            $lastGood->formula = null;
        }
        $this->categories = $goodCategories;
    }

    /**
     * Build the formula starting from the currently defined categories.
     *
     * @return string
     */
    private function buildFormula()
    {
        $numCategories = \count($this->categories);
        switch ($numCategories) {
            case 1:
                // Just one category
                return '0';
            case 2:
                return self::reduceFormula(self::reverseFormula($this->categories[0]->formula));
            default:
                $formula = (string) ($numCategories - 1);
                for ($i = $numCategories - 2; $i >= 0; $i--) {
                    $f = self::reduceFormula($this->categories[$i]->formula);
                    if (!\preg_match('/^\([^()]+\)$/', $f)) {
                        $f = "($f)";
                    }
                    $formula = "$f ? $i : $formula";
                    if ($i > 0) {
                        $formula = "($formula)";
                    }
                }

                return $formula;
        }
    }

    /**
     * Reverse a formula.
     *
     * @param string $formula
     *
     * @throws \InvalidArgumentException
     * @return string
     */
    private static function reverseFormula($formula)
    {
        if (\preg_match('/^n( % \d+)? == \d+(\.\.\d+|,\d+)*?$/', $formula)) {
            return \str_replace(' == ', ' != ', $formula);
        }
        if (\preg_match('/^n( % \d+)? != \d+(\.\.\d+|,\d+)*?$/', $formula)) {
            return \str_replace(' != ', ' == ', $formula);
        }
        if (\preg_match('/^\(?n == \d+ \|\| n == \d+\)?$/', $formula)) {
            return \trim(\str_replace([' == ', ' || '], [' != ', ' && '], $formula), '()');
        }
        $m = null;
        if (\preg_match('/^(n(?: % \d+)?) == (\d+) && (n(?: % \d+)?) != (\d+)$/', $formula, $m)) {
            return "{$m[1]} != {$m[2]} || {$m[3]} == {$m[4]}";
        }
        switch ($formula) {
            case '(n == 1 || n == 2 || n == 3) || n % 10 != 4 && n % 10 != 6 && n % 10 != 9':
                return 'n != 1 && n != 2 && n != 3 && (n % 10 == 4 || n % 10 == 6 || n % 10 == 9)';
            case '(n == 0 || n == 1) || n >= 11 && n <= 99':
                return 'n >= 2 && (n < 11 || n > 99)';
        }
        throw new \InvalidArgumentException("Unable to reverse the formula '$formula'");
    }

    /**
     * Reduce some excessively complex formulas.
     *
     * @param string $formula
     *
     * @return string
     */
    private static function reduceFormula($formula)
    {
        static $map = [
            'n != 0 && n != 1' => 'n > 1',
            '(n == 0 || n == 1) && n != 0' => 'n == 1',
        ];

        return $map[$formula] ?? $formula;
    }


    /**
     * Returns a clone of this instance with all the strings to US-ASCII.
     *
     * @return Language
     */
    public function getUSAsciiClone()
    {
        $clone = clone $this;
        $clone->name = Strings::toAscii($clone->name);
        $clone->formula = Strings::toAscii($clone->formula);
        $clone->categories = [];
        foreach ($this->categories as $category) {
            $categoryClone = clone $category;
            $categoryClone->examples = Strings::toAscii($categoryClone->examples);
            $clone->categories[] = $categoryClone;
        }

        return $clone;
    }
}