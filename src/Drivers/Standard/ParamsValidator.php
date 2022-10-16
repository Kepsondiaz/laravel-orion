<?php

namespace Orion\Drivers\Standard;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Orion\Exceptions\MaxNestedDepthExceededException;
use Orion\Helpers\ArrayHelper;
use Orion\Helpers\RequestHelper;
use Orion\Http\Requests\Request;
use Orion\Http\Rules\WhitelistedField;
use Orion\Http\Rules\WhitelistedQueryFields;

class ParamsValidator implements \Orion\Contracts\ParamsValidator
{
    /**
     * @var string[]
     */
    private $exposedScopes;

    /**
     * @var string[]
     */
    private $filterableBy;

    /**
     * @var string[]
     */
    private $sortableBy;

    /**
     * @var string[]
     */
    private $aggregatableBy;

    /**
     * @var string[]
     */
    private $includableBy;

    /**
     * @inheritDoc
     */
    public function __construct(array $exposedScopes = [], array $filterableBy = [], array $sortableBy = [], array $aggregatableBy = [], array $includableBy = [])
    {
        $this->exposedScopes = $exposedScopes;
        $this->filterableBy = $filterableBy;
        $this->sortableBy = $sortableBy;
        $this->aggregatableBy = $aggregatableBy;
        $this->includableBy = $includableBy;
    }

    public function validateScopes(Request $request): void
    {
        Validator::make(
            $request->all(),
            [
                'scopes' => ['sometimes', 'array'],
                'scopes.*.name' => ['required_with:scopes', 'in:'.implode(',', $this->exposedScopes)],
                'scopes.*.parameters' => ['sometimes', 'array'],
            ]
        )->validate();
    }

    public function validateFilters(Request $request): void
    {
        $depth = $this->nestedFiltersDepth($request->input('filters', []));

        Validator::make(
            $request->all(),
            array_merge([
                'filters' => ['sometimes', 'array'],
            ], $this->getNestedRules('filters', $depth))
        )->validate();
    }

    /**
     * @throws MaxNestedDepthExceededException
     */
    protected function nestedFiltersDepth($array, $modifier = 0) {
        $depth = ArrayHelper::depth($array);
        $configMaxNestedDepth = config('orion.search.max_nested_depth', 1);

        // Here we calculate the real nested filters depth
        $depth = floor($depth / 2);

        if ($depth + $modifier > $configMaxNestedDepth) {
            throw new MaxNestedDepthExceededException(422, __('Max nested depth :depth is exceeded', ['depth' => $configMaxNestedDepth]));
        }

        return $depth;
    }

    /**
     * @param string $prefix
     * @param int $maxDepth
     * @param array $filterableBy
     * @param array $rules
     * @param int $currentDepth
     * @return array
     */
    protected function getNestedRules(string $prefix, int $maxDepth, array $rules = [], int $currentDepth = 1): array
    {
        $rules = array_merge($rules, [
            $prefix.'.*.type' => ['sometimes', 'in:and,or'],
            $prefix.'.*.field' => [
                "required_without:{$prefix}.*.nested",
                'regex:/^[\w.\_\-\>]+$/',
                new WhitelistedField($this->filterableBy),
            ],
            $prefix.'.*.operator' => [
                'sometimes',
                'in:<,<=,>,>=,=,!=,like,not like,ilike,not ilike,in,not in,all in,any in',
            ],
            $prefix.'.*.value' => ['nullable'],
            $prefix.'.*.nested' => ['sometimes', 'array',],
        ]);

        if ($maxDepth >= $currentDepth) {
            $rules = array_merge(
                $rules,
                $this->getNestedRules("{$prefix}.*.nested", $maxDepth, $rules, ++$currentDepth)
            );
        }

        return $rules;
    }

    public function validateSort(Request $request): void
    {
        Validator::make(
            $request->all(),
            [
                'sort' => ['sometimes', 'array'],
                'sort.*.field' => [
                    'required_with:sort',
                    'regex:/^[\w.\_\-\>]+$/',
                    new WhitelistedField($this->sortableBy),
                ],
                'sort.*.direction' => ['sometimes', 'in:asc,desc'],
            ]
        )->validate();
    }

    public function validateSearch(Request $request): void
    {
        Validator::make(
            $request->all(),
            [
                'search' => ['sometimes', 'array'],
                'search.value' => ['string', 'nullable'],
                'search.case_sensitive' => ['bool'],
            ]
        )->validate();
    }

    public function validateAggregators(Request $request): void
    {
        $depth = $this->nestedFiltersDepth(RequestHelper::getPostRequestParam('aggregate', []), -1);

        Validator::make(
            RequestHelper::getPostRequestParam(),
            array_merge(
                [
                    'aggregate' => ['sometimes', 'array'],
                    'aggregate.*.relation' => [
                        'required',
                        'regex:/^[\w.\_\-\>]+$/',
                    ],
                    'aggregate.*.field' => [
                        'prohibited_if:aggregate.*.type,count',
                        'prohibited_if:aggregate.*.type,exists',
                        'required_if:aggregate.*.type,avg,sum,min,max'
                    ],
                    'aggregate.*.type' => [
                        'required',
                        'in:count,min,max,avg,sum,exists'
                    ],
                    'aggregate.*.filters' => ['sometimes', 'array'],
                ],
                $this->getNestedRules('aggregate.*.filters', $depth)
            )
        )->validate();

        // @TODO: make the error more logical by including key and replicated in tests errors 422
        // Here we regroup the "relation" and "field" fields to validate them
        Validator::make(
            collect(RequestHelper::getPostRequestParam()['aggregate'] ?? [])
                ->transform(function ($aggregate) {
                    return isset($aggregate['field']) ? "{$aggregate['relation']}.{$aggregate['field']}" : $aggregate['relation'];
                })->all(),
            [
                '*' => new WhitelistedQueryFields($this->aggregatableBy)
            ]
        )->validate();

        Validator::make(
            $request->query(),
            [
                'with_count' => ['sometimes', 'string', 'not_regex:/\\./', new WhitelistedQueryFields($this->aggregatableBy)],
                'with_exists' => ['sometimes', 'string', 'not_regex:/\\./', new WhitelistedQueryFields($this->aggregatableBy)],
                'with_min' => ['sometimes', 'string', 'regex:/^[a-z\d,]*\.+[a-z\d,]*$/', new WhitelistedQueryFields($this->aggregatableBy)],
                'with_max' => ['sometimes', 'string', 'regex:/^[a-z\d,]*\.+[a-z\d,]*$/', new WhitelistedQueryFields($this->aggregatableBy)],
                'with_avg' => ['sometimes', 'string', 'regex:/^[a-z\d,]*\.+[a-z\d,]*$/', new WhitelistedQueryFields($this->aggregatableBy)],
                'with_sum' => ['sometimes', 'string', 'regex:/^[a-z\d,]*\.+[a-z\d,]*$/', new WhitelistedQueryFields($this->aggregatableBy)],
            ]
        )->validate();
    }

    public function validateIncludes(Request $request): void
    {
        $depth = $this->nestedFiltersDepth(RequestHelper::getPostRequestParam('include', []), -1);

        Validator::make(
            RequestHelper::getPostRequestParam(),
            array_merge(
                [
                    'include' => ['sometimes', 'array'],
                    'include.*.relation' => [
                        'required',
                        'regex:/^[\w.\_\-\>]+$/',
                        new WhitelistedField($this->includableBy),
                    ],
                    'include.*.filters' => ['sometimes', 'array'],
                ],
                $this->getNestedRules('include.*.filters', $depth)
            )
        )->validate();

        Validator::make(
            $request->query(),
            [
                'include' => ['sometimes', 'string', new WhitelistedQueryFields($this->includableBy)],
            ]
        )->validate();
    }
}
