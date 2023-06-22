<?php

namespace Toflar\StateSetIndex;

use Toflar\StateSetIndex\Alphabet\AlphabetInterface;
use Toflar\StateSetIndex\StateSet\CostAnnotatedStateSet;
use Toflar\StateSetIndex\StateSet\StateSetInterface;

class StateSetIndex
{
    public function __construct(
        private Config $config,
        private AlphabetInterface $alphabet,
        private StateSetInterface $stateSet
    ) {
    }

    /**
     * Indexes an array of strings and returns an array where all strings have their state assigned.
     *
     * @return array<string, int>
     */
    public function index(array $strings): array
    {
        $assigned = [];

        foreach ($strings as $string) {
            $state = 0;
            $this->loopOverEveryCharacter($string, function (int $mappedChar) use (&$state) {
                $newState = (int) ($state * $this->config->getAlphabetSize() + $mappedChar);

                $this->stateSet->add($newState, $state, $mappedChar);
                $state = $newState;
            });

            $assigned[$string] = $state;
            $this->stateSet->acceptString($state, $string);
        }

        return $assigned;
    }

    /**
     * Returns the matching strings.
     *
     * @return array<string>
     */
    public function find(string $string, int $editDistance): array
    {
        $acceptedStringsPerState = $this->findAcceptedStrings($string, $editDistance);

        $filtered = [];

        foreach ($acceptedStringsPerState as $acceptedStrings) {
            foreach ($acceptedStrings as $acceptedString) {
                if (Levenshtein::distance($string, $acceptedString) <= $editDistance) {
                    $filtered[] = $acceptedString;
                }
            }
        }

        return array_unique($filtered);
    }

    /**
     * Returns the matching strings per state. Key is the state and the value is an array of matching strings
     * for that state.
     *
     * @return array<int,array<string>>
     */
    public function findAcceptedStrings(string $string, int $editDistance): array
    {
        $states = $this->findMatchingStates($string, $editDistance);

        return $this->stateSet->getAcceptedStrings($states);
    }

    /**
     * Returns the matching states.
     *
     * @return array<int>
     */
    public function findMatchingStates(string $string, int $editDistance)
    {
        $states = $this->stateSet->getReachableStates(0, $editDistance);

        $this->loopOverEveryCharacter($string, function (int $mappedChar) use (&$states, $editDistance) {
            $nextStates = new CostAnnotatedStateSet();

            foreach ($states->all() as $state => $cost) {
                $newStates = new CostAnnotatedStateSet();

                // Deletion
                if ($cost + 1 <= $editDistance) {
                    $newStates->add($state, $cost + 1);
                }

                foreach ($this->stateSet->getChildrenOfState($state) as $childState) {
                    $childChar = $this->stateSet->getCharForState($childState);
                    if ($childChar === $mappedChar) {
                        // Match
                        $newStates->add($childState, $cost);
                    } elseif ($cost + 1 <= $editDistance) {
                        // Substitution
                        $newStates->add($childState, $cost + 1);
                    }
                }

                // Insertion
                foreach ($newStates->all() as $newState => $newCost) {
                    $nextStates = $nextStates->mergeWith($this->stateSet->getReachableStates(
                        $newState,
                        $editDistance,
                        $newCost
                    ));
                }
            }

            $states = $nextStates;
        });

        return $states->states();
    }

    /**
     * @param \Closure(int) $closure
     */
    private function loopOverEveryCharacter(string $string, \Closure $closure): void
    {
        $indexedSubstringLength = min($this->config->getIndexLength(), mb_strlen($string));
        $indexedSubstring = mb_substr($string, 0, $indexedSubstringLength);

        foreach (mb_str_split($indexedSubstring) as $char) {
            $mappedChar = $this->alphabet->map($char, $this->config->getAlphabetSize());
            $closure($mappedChar);
        }
    }
}
