<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Core\Security\ContentSecurityPolicy;

use TYPO3\CMS\Core\Security\Nonce;
use TYPO3\CMS\Core\Type\Map;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Representation of the whole Content-Security-Policy
 * see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Security-Policy
 *
 * @internal This implementation still might be adjusted
 */
class Policy
{
    /**
     * @var Map<Directive, SourceCollection>
     */
    protected Map $directives;

    /**
     * @param SourceCollection|SourceInterface ...$sources (optional) default-src sources
     */
    public function __construct(SourceCollection|SourceInterface ...$sources)
    {
        $this->directives = new Map();
        $collection = $this->asMergedSourceCollection(...$sources);
        if (!$collection->isEmpty()) {
            $this->directives[Directive::DefaultSrc] = $collection;
        }
    }

    public function isEmpty(): bool
    {
        return count($this->directives) === 0;
    }

    /**
     * Applies mutations/changes to the current policy.
     */
    public function mutate(MutationCollection|Mutation ...$mutations): self
    {
        $self = $this;
        foreach ($mutations as $mutation) {
            if ($mutation instanceof MutationCollection) {
                $self = $self->mutate(...$mutation->mutations);
            } elseif ($mutation->mode === MutationMode::Set) {
                $self = $self->set($mutation->directive, ...$mutation->sources);
            } elseif ($mutation->mode === MutationMode::Extend) {
                $self = $self->extend($mutation->directive, ...$mutation->sources);
            } elseif ($mutation->mode === MutationMode::Reduce) {
                $self = $self->reduce($mutation->directive, ...$mutation->sources);
            } elseif ($mutation->mode === MutationMode::Remove) {
                $self = $self->remove($mutation->directive);
            }
        }
        return $self;
    }

    /**
     * Sets (overrides) the 'default-src' directive, which is also the fall-back for other more specific directives.
     */
    public function default(SourceCollection|SourceInterface ...$sources): self
    {
        return $this->set(Directive::DefaultSrc, ...$sources);
    }

    /**
     * Extends a specific directive, either by appending sources or by inheriting from an ancestor directive.
     */
    public function extend(Directive $directive, SourceCollection|SourceInterface ...$sources): self
    {
        $collection = $this->asMergedSourceCollection(...$sources);
        if ($collection->isEmpty()) {
            return $this;
        }
        foreach ($directive->getAncestors() as $ancestorDirective) {
            if ($this->has($ancestorDirective)) {
                $ancestorCollection = $this->directives[$ancestorDirective];
                break;
            }
        }
        $targetCollection = $this->asMergedSourceCollection(...array_filter([
            $ancestorCollection ?? null,
            $this->directives[$directive] ?? null,
            $collection,
        ]));
        return $this->changeDirectiveSources($directive, $targetCollection);
    }

    public function reduce(Directive $directive, SourceCollection|SourceInterface ...$sources): self
    {
        if (!$this->has($directive)) {
            return $this;
        }
        $collection = $this->asMergedSourceCollection(...$sources);
        $targetCollection = $this->directives[$directive]->exclude($collection);
        return $this->changeDirectiveSources($directive, $targetCollection);
    }

    /**
     * Sets (overrides) a specific directive.
     */
    public function set(Directive $directive, SourceCollection|SourceInterface ...$sources): self
    {
        $collection = $this->asMergedSourceCollection(...$sources);
        return $this->changeDirectiveSources($directive, $collection);
    }

    /**
     * Removes a specific directive.
     */
    public function remove(Directive $directive): self
    {
        if (!$this->has($directive)) {
            return $this;
        }
        $target = clone $this;
        unset($target->directives[$directive]);
        return $target;
    }

    /**
     * Sets the 'report-uri' directive and appends 'report-sample' to existing & applicable directives.
     */
    public function report(UriValue $reportUri): self
    {
        $target = $this->set(Directive::ReportUri, $reportUri);
        $reportSample = SourceKeyword::reportSample;
        foreach ($target->directives as $directive => $collection) {
            if ($reportSample->isApplicable($directive)) {
                $target->directives[$directive] = $collection->with($reportSample);
            }
        }
        return $target;
    }

    public function has(Directive $directive): bool
    {
        return isset($this->directives[$directive]);
    }

    /**
     * Prepares the policy for finally being serialized and issued as HTTP header.
     * This step aims to optimize several combinations, or adjusts directives when 'strict-dynamic' is used.
     */
    public function prepare(): self
    {
        $purged = false;
        $directives = clone $this->directives;
        $comparator = [$this, 'compareSources'];
        foreach ($directives as $directive => $collection) {
            foreach ($directive->getAncestors() as $ancestorDirective) {
                $ancestorCollection = $directives[$ancestorDirective] ?? null;
                if ($ancestorCollection !== null
                    && array_udiff($collection->sources, $ancestorCollection->sources, $comparator) === []
                    && array_udiff($ancestorCollection->sources, $collection->sources, $comparator) === []
                ) {
                    $purged = true;
                    unset($directives[$directive]);
                    continue 2;
                }
            }
        }
        foreach ($directives as $directive => $collection) {
            // applies implicit changes to sources in case 'strict-dynamic' is used
            if ($collection->contains(SourceKeyword::strictDynamic)) {
                $directives[$directive] = SourceKeyword::strictDynamic->applySourceImplications($collection) ?? $collection;
            }
        }
        if (!$purged) {
            return $this;
        }
        $target = clone $this;
        $target->directives = $directives;
        return $target;
    }

    /**
     * Compiles this policy and returns the serialized representation to be used as HTTP header value.
     *
     * @param Nonce $nonce used to substitute `SourceKeyword::nonceProxy` items during compilation
     */
    public function compile(Nonce $nonce): string
    {
        $policyParts = [];
        $service = GeneralUtility::makeInstance(ModelService::class);
        foreach ($this->prepare()->directives as $directive => $collection) {
            $directiveParts = $service->compileSources($nonce, $collection);
            if ($directiveParts !== []) {
                array_unshift($directiveParts, $directive->value);
                $policyParts[] = implode(' ', $directiveParts);
            }
        }
        return implode('; ', $policyParts);
    }

    public function containsDirective(Directive $directive, SourceCollection|SourceInterface ...$sources): bool
    {
        $sources = $this->asMergedSourceCollection(...$sources);
        return (bool)$this->directives[$directive]?->contains(...$sources->sources);
    }

    public function coversDirective(Directive $directive, SourceCollection|SourceInterface ...$sources): bool
    {
        $sources = $this->asMergedSourceCollection(...$sources);
        return (bool)$this->directives[$directive]?->covers(...$sources->sources);
    }

    /**
     * Whether the current policy contains another policy (in terms of instances and values, but without inference).
     */
    public function contains(Policy $other): bool
    {
        if ($other->isEmpty()) {
            return false;
        }
        foreach ($other->directives as $directive => $collection) {
            if (!$this->containsDirective($directive, $collection)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether the current policy covers another policy (in terms of CSP inference, considering wildcards and similar).
     */
    public function covers(Policy $other): bool
    {
        if ($other->isEmpty()) {
            return false;
        }
        foreach ($other->directives as $directive => $collection) {
            if (!$this->coversDirective($directive, $collection)) {
                return false;
            }
        }
        return true;
    }

    protected function compareSources(SourceInterface $a, SourceInterface $b): int
    {
        $service = GeneralUtility::makeInstance(ModelService::class);
        return $service->serializeSource($a) <=> $service->serializeSource($b);
    }

    protected function changeDirectiveSources(Directive $directive, SourceCollection $sources): self
    {
        if ($sources->isEmpty()) {
            return $this;
        }
        $target = clone $this;
        $target->directives[$directive] = $sources;
        return $target;
    }

    protected function asMergedSourceCollection(SourceCollection|SourceInterface ...$subjects): SourceCollection
    {
        $collections = array_filter($subjects, static fn ($source) => $source instanceof SourceCollection);
        $sources = array_filter($subjects, static fn ($source) => !$source instanceof SourceCollection);
        if ($sources !== []) {
            $collections[] = new SourceCollection(...$sources);
        }
        $target = new SourceCollection();
        foreach ($collections as $collection) {
            $target = $target->merge($collection);
        }
        return $target;
    }
}
