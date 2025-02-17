<?php

/**
 * SCSSPHP
 *
 * @copyright 2012-2020 Leaf Corcoran
 *
 * @license http://opensource.org/licenses/MIT MIT
 *
 * @link http://scssphp.github.io/scssphp
 */

namespace ScssPhp\ScssPhp\Ast\Selector;

use ScssPhp\ScssPhp\Exception\SassFormatException;
use ScssPhp\ScssPhp\Extend\ExtendUtil;
use ScssPhp\ScssPhp\Logger\LoggerInterface;
use ScssPhp\ScssPhp\Parser\SelectorParser;
use ScssPhp\ScssPhp\Util\EquatableUtil;
use ScssPhp\ScssPhp\Util\ListUtil;
use ScssPhp\ScssPhp\Visitor\SelectorVisitor;

/**
 * A complex selector.
 *
 * A complex selector is composed of {@see CompoundSelector}s separated by
 * {@see Combinator}s. It selects elements based on their parent selectors.
 */
final class ComplexSelector extends Selector
{
    /**
     * This selector's leading combinators.
     *
     * If this is empty, that indicates that it has no leading combinator. If
     * it's more than one element, that means it's invalid CSS; however, we still
     * support this for backwards-compatibility purposes.
     *
     * @var list<string>
     * @phpstan-var list<Combinator::*>
     * @readonly
     */
    private $leadingCombinators;

    /**
     * The components of this selector.
     *
     * This is only empty if {@see $leadingCombinators} is not empty.
     *
     * Descendant combinators aren't explicitly represented here. If two
     * {@see CompoundSelector}s are adjacent to one another, there's an implicit
     * descendant combinator between them.
     *
     * It's possible for multiple {@see Combinator}s to be adjacent to one another.
     * This isn't valid CSS, but Sass supports it for CSS hack purposes.
     *
     * @var list<ComplexSelectorComponent>
     * @readonly
     */
    private $components;

    /**
     * Whether a line break should be emitted *before* this selector.
     *
     * @var bool
     * @readonly
     */
    private $lineBreak;

    /**
     * @var int|null
     */
    private $minSpecificity;

    /**
     * @var int|null
     */
    private $maxSpecificity;

    /**
     * @param list<string>                   $leadingCombinators
     * @param list<ComplexSelectorComponent> $components
     * @param bool                           $lineBreak
     *
     * @phpstan-param list<Combinator::*> $leadingCombinators
     */
    public function __construct(array $leadingCombinators, array $components, bool $lineBreak = false)
    {
        if ($leadingCombinators === [] && $components === []) {
            throw new \InvalidArgumentException('leadingCombinators and components may not both be empty.');
        }

        $this->leadingCombinators = $leadingCombinators;
        $this->components = $components;
        $this->lineBreak = $lineBreak;
    }

    /**
     * Parses a complex selector from $contents.
     *
     * If passed, $url is the name of the file from which $contents comes.
     * $allowParent controls whether a {@see ParentSelector} is allowed in this
     * selector.
     *
     * @throws SassFormatException if parsing fails.
     */
    public static function parse(string $contents, ?LoggerInterface $logger = null, ?string $url = null, bool $allowParent = true): ComplexSelector
    {
        return (new SelectorParser($contents, $logger, $url, $allowParent))->parseComplexSelector();
    }

    /**
     * @return list<string>
     * @phpstan-return list<Combinator::*>
     */
    public function getLeadingCombinators(): array
    {
        return $this->leadingCombinators;
    }

    /**
     * @return list<ComplexSelectorComponent>
     */
    public function getComponents(): array
    {
        return $this->components;
    }

    /**
     * If this compound selector is composed of a single compound selector with
     * no combinators, returns it.
     *
     * Otherwise, returns null.
     *
     * @return CompoundSelector|null
     */
    public function getSingleCompound(): ?CompoundSelector
    {
        if (\count($this->leadingCombinators) === 0 && \count($this->components) === 1 && \count($this->components[0]->getCombinators()) === 0) {
            return $this->components[0]->getSelector();
        }

        return null;
    }

    public function getLastComponent(): ComplexSelectorComponent
    {
        if (\count($this->components) === 0) {
            throw new \OutOfBoundsException('Cannot get the last component of an empty list.');
        }

        return $this->components[\count($this->components) - 1];
    }

    public function getLineBreak(): bool
    {
        return $this->lineBreak;
    }

    public function getMinSpecificity(): int
    {
        if ($this->minSpecificity === null) {
            $this->computeSpecificity();
            assert($this->minSpecificity !== null);
        }

        return $this->minSpecificity;
    }

    public function getMaxSpecificity(): int
    {
        if ($this->maxSpecificity === null) {
            $this->computeSpecificity();
            assert($this->maxSpecificity !== null);
        }

        return $this->maxSpecificity;
    }

    public function accept(SelectorVisitor $visitor)
    {
        return $visitor->visitComplexSelector($this);
    }

    /**
     * Whether this is a superselector of $other.
     *
     * That is, whether this matches every element that $other matches, as well
     * as possibly additional elements.
     */
    public function isSuperselector(ComplexSelector $other): bool
    {
        return \count($this->leadingCombinators) === 0 && \count($other->leadingCombinators) ===0 && ExtendUtil::complexIsSuperselector($this->components, $other->components);
    }

    public function equals(object $other): bool
    {
        return $other instanceof ComplexSelector && $this->leadingCombinators === $other->leadingCombinators && EquatableUtil::listEquals($this->components, $other->components);
    }

    /**
     * Computes {@see minSpecificity} and {@see maxSpecificity}.
     */
    private function computeSpecificity(): void
    {
        $minSpecificity = 0;
        $maxSpecificity = 0;

        foreach ($this->components as $component) {
            $minSpecificity += $component->getSelector()->getMinSpecificity();
            $maxSpecificity += $component->getSelector()->getMaxSpecificity();
        }

        $this->minSpecificity = $minSpecificity;
        $this->maxSpecificity = $maxSpecificity;
    }


    /**
     * Returns a copy of `$this` with $combinators added to the end of the final
     * component in {@see components}.
     *
     * If $forceLineBreak is `true`, this will mark the new complex selector as
     * having a line break.
     *
     * @param list<string> $combinators
     * @param bool         $forceLineBreak
     *
     * @return ComplexSelector
     *
     * @phpstan-param list<Combinator::*> $combinators
     */
    public function withAdditionalCombinators(array $combinators, bool $forceLineBreak = false): ComplexSelector
    {
        if ($combinators === []) {
            return $this;
        }

        if ($this->components === []) {
            return new ComplexSelector(array_merge($this->leadingCombinators, $combinators), [], $this->lineBreak || $forceLineBreak);
        }

        return new ComplexSelector(
            $this->leadingCombinators,
            array_merge(
                ListUtil::exceptLast($this->components),
                [ListUtil::last($this->components)->withAdditionalCombinators($combinators)]
            ),
            $this->lineBreak || $forceLineBreak
        );
    }

    /**
     * Returns a copy of `$this` with an additional $component added to the end.
     *
     * If $forceLineBreak is `true`, this will mark the new complex selector as
     * having a line break.
     *
     * @param ComplexSelectorComponent $component
     * @param bool                     $forceLineBreak
     *
     * @return ComplexSelector
     */
    public function withAdditionalComponent(ComplexSelectorComponent $component, bool $forceLineBreak = false): ComplexSelector
    {
        return new ComplexSelector($this->leadingCombinators, array_merge($this->components, [$component]), $this->lineBreak || $forceLineBreak);
    }

    /**
     * Returns a copy of `this` with $child's combinators added to the end.
     *
     * If $child has {@see leadingCombinators}, they're appended to `this`'s last
     * combinator. This does _not_ resolve parent selectors.
     *
     * If $forceLineBreak is `true`, this will mark the new complex selector as
     * having a line break.
     *
     * @param ComplexSelector $child
     * @param bool            $forceLineBreak
     *
     * @return ComplexSelector
     */
    public function concatenate(ComplexSelector $child, bool $forceLineBreak = false): ComplexSelector
    {
        if (\count($child->leadingCombinators) === 0) {
            return new ComplexSelector(
                $this->leadingCombinators,
                array_merge($this->components, $child->components),
                $this->lineBreak || $child->lineBreak || $forceLineBreak
            );
        }

        if (\count($this->components) === 0) {
            return new ComplexSelector(
                array_merge($this->leadingCombinators, $child->leadingCombinators),
                $child->components,
                $this->lineBreak || $child->lineBreak || $forceLineBreak
            );
        }

        return new ComplexSelector(
            $this->leadingCombinators,
            array_merge(
                ListUtil::exceptLast($this->components),
                [ListUtil::last($this->components)->withAdditionalCombinators($child->leadingCombinators)],
                $child->components
            ),
            $this->lineBreak || $child->lineBreak || $forceLineBreak
        );
    }
}
