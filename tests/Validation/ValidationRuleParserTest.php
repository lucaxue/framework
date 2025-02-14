<?php

namespace Illuminate\Tests\Validation;

use Illuminate\Support\Fluent;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationRuleParser;
use PHPUnit\Framework\TestCase;

class ValidationRuleParserTest extends TestCase
{
    public function testConditionalRulesAreProperlyExpandedAndFiltered()
    {
        $rules = ValidationRuleParser::filterConditionalRules([
            'name' => Rule::when(true, ['required', 'min:2']),
            'email' => Rule::when(false, ['required', 'min:2']),
            'password' => Rule::when(true, 'required|min:2'),
            'username' => ['required', Rule::when(true, ['min:2'])],
            'address' => ['required', Rule::when(false, ['min:2'])],
            'city' => ['required', Rule::when(function (Fluent $input) {
                return true;
            }, ['min:2'])],
            'state' => ['required', Rule::when(true, function (Fluent $input) {
                return 'min:2';
            })],
            'zip' => ['required', Rule::when(false, [], function (Fluent $input) {
                return ['min:2'];
            })],
        ]);

        $this->assertEquals([
            'name' => ['required', 'min:2'],
            'email' => [],
            'password' => ['required', 'min:2'],
            'username' => ['required', 'min:2'],
            'address' => ['required'],
            'city' => ['required', 'min:2'],
            'state' => ['required', 'min:2'],
            'zip' => ['required', 'min:2'],
        ], $rules);
    }

    public function testEmptyRulesArePreserved()
    {
        $rules = ValidationRuleParser::filterConditionalRules([
            'name' => [],
            'email' => '',
            'password' => Rule::when(true, 'required|min:2'),
        ]);

        $this->assertEquals([
            'name' => [],
            'email' => '',
            'password' => ['required', 'min:2'],
        ], $rules);
    }

    public function testConditionalRulesWithDefault()
    {
        $rules = ValidationRuleParser::filterConditionalRules([
            'name' => Rule::when(true, ['required', 'min:2'], ['string', 'max:10']),
            'email' => Rule::when(false, ['required', 'min:2'], ['string', 'max:10']),
            'password' => Rule::when(false, 'required|min:2', 'string|max:10'),
            'username' => ['required', Rule::when(true, ['min:2'], ['string', 'max:10'])],
            'address' => ['required', Rule::when(false, ['min:2'], ['string', 'max:10'])],
        ]);

        $this->assertEquals([
            'name' => ['required', 'min:2'],
            'email' => ['string', 'max:10'],
            'password' => ['string', 'max:10'],
            'username' => ['required', 'min:2'],
            'address' => ['required', 'string', 'max:10'],
        ], $rules);
    }

    public function testEmptyConditionalRulesArePreserved()
    {
        $rules = ValidationRuleParser::filterConditionalRules([
            'name' => Rule::when(true, '', ['string', 'max:10']),
            'email' => Rule::when(false, ['required', 'min:2'], []),
            'password' => Rule::when(false, 'required|min:2', 'string|max:10'),
        ]);

        $this->assertEquals([
            'name' => [],
            'email' => [],
            'password' => ['string', 'max:10'],
        ], $rules);
    }

    public function testExplodeProperlyParsesSingleRegexRule()
    {
        $data = ['items' => [['type' => 'foo']]];

        $exploded = (new ValidationRuleParser($data))->explode(
            ['items.*.type' => 'regex:/^(foo|bar)$/i']
        );

        $this->assertEquals('regex:/^(foo|bar)$/i', $exploded->rules['items.0.type'][0]);
    }

    public function testExplodeProperlyParsesRegexWithArrayOfRules()
    {
        $data = ['items' => [['type' => 'foo']]];

        $exploded = (new ValidationRuleParser($data))->explode(
            ['items.*.type' => ['in:foo', 'regex:/^(foo|bar)$/i']]
        );

        $this->assertEquals('in:foo', $exploded->rules['items.0.type'][0]);
        $this->assertEquals('regex:/^(foo|bar)$/i', $exploded->rules['items.0.type'][1]);
    }

    public function testExplodeProperlyParsesRegexThatDoesNotContainPipe()
    {
        $data = ['items' => [['type' => 'foo']]];

        $exploded = (new ValidationRuleParser($data))->explode(
            ['items.*.type' => 'in:foo|regex:/^(bar)$/i']
        );

        $this->assertEquals('in:foo', $exploded->rules['items.0.type'][0]);
        $this->assertEquals('regex:/^(bar)$/i', $exploded->rules['items.0.type'][1]);
    }

    public function testExplodeFailsParsingRegexWithOtherRulesInSingleString()
    {
        $data = ['items' => [['type' => 'foo']]];

        $exploded = (new ValidationRuleParser($data))->explode(
            ['items.*.type' => 'in:foo|regex:/^(foo|bar)$/i']
        );

        $this->assertEquals('in:foo', $exploded->rules['items.0.type'][0]);
        $this->assertEquals('regex:/^(foo', $exploded->rules['items.0.type'][1]);
        $this->assertEquals('bar)$/i', $exploded->rules['items.0.type'][2]);
    }

    public function testExplodeProperlyFlattensRuleArraysOfArrays()
    {
        $data = ['items' => [['type' => 'foo']]];

        $exploded = (new ValidationRuleParser($data))->explode(
            ['items.*.type' => ['in:foo', [[['regex:/^(foo|bar)$/i']]]]]
        );

        $this->assertEquals('in:foo', $exploded->rules['items.0.type'][0]);
        $this->assertEquals('regex:/^(foo|bar)$/i', $exploded->rules['items.0.type'][1]);
    }

    public function testExplodeGeneratesNestedRules()
    {
        $parser = (new ValidationRuleParser([
            'users' => [
                ['name' => 'Taylor Otwell'],
            ],
        ]));

        $results = $parser->explode([
            'users.*.name' => Rule::forEach(function ($value, $attribute, $data) {
                $this->assertEquals('Taylor Otwell', $value);
                $this->assertEquals('users.0.name', $attribute);
                $this->assertEquals($data['users.0.name'], 'Taylor Otwell');

                return [Rule::requiredIf(true)];
            }),
        ]);

        $this->assertEquals(['users.0.name' => ['required']], $results->rules);
        $this->assertEquals(['users.*.name' => ['users.0.name']], $results->implicitAttributes);
    }

    public function testExplodeGeneratesNestedRulesForNonNestedData()
    {
        $parser = (new ValidationRuleParser([
            'name' => 'Taylor Otwell',
        ]));

        $results = $parser->explode([
            'name' => Rule::forEach(function ($value, $attribute, $data = null) {
                $this->assertEquals('Taylor Otwell', $value);
                $this->assertEquals('name', $attribute);
                $this->assertEquals(['name' => 'Taylor Otwell'], $data);

                return 'required';
            }),
        ]);

        $this->assertEquals(['name' => ['required']], $results->rules);
        $this->assertEquals([], $results->implicitAttributes);
    }

    public function testExplodeHandlesArraysOfNestedRules()
    {
        $parser = (new ValidationRuleParser([
            'users' => [
                ['name' => 'Taylor Otwell'],
                ['name' => 'Abigail Otwell'],
            ],
        ]));

        $results = $parser->explode([
            'users.*.name' => [
                Rule::forEach(function ($value, $attribute, $data) {
                    $this->assertEquals([
                        'users.0.name' => 'Taylor Otwell',
                        'users.1.name' => 'Abigail Otwell',
                    ], $data);

                    return [Rule::requiredIf(true)];
                }),
                Rule::forEach(function ($value, $attribute, $data) {
                    $this->assertEquals([
                        'users.0.name' => 'Taylor Otwell',
                        'users.1.name' => 'Abigail Otwell',
                    ], $data);

                    return [
                        $value === 'Taylor Otwell'
                            ? Rule::in('taylor')
                            : Rule::in('abigail'),
                    ];
                }),
            ],
        ]);

        $this->assertEquals([
            'users.0.name' => ['required', 'in:"taylor"'],
            'users.1.name' => ['required', 'in:"abigail"'],
        ], $results->rules);

        $this->assertEquals([
            'users.*.name' => [
                'users.0.name',
                'users.0.name',
                'users.1.name',
                'users.1.name',
            ],
        ], $results->implicitAttributes);
    }

    public function testExplodeHandlesRecursivelyNestedRules()
    {
        $parser = (new ValidationRuleParser([
            'users' => [['name' => 'Taylor Otwell']],
        ]));

        $results = $parser->explode([
            'users.*.name' => [
                Rule::forEach(function ($value, $attribute, $data) {
                    $this->assertEquals('Taylor Otwell', $value);
                    $this->assertEquals('users.0.name', $attribute);
                    $this->assertEquals(['users.0.name' => 'Taylor Otwell'], $data);

                    return Rule::forEach(function ($value, $attribute, $data) {
                        $this->assertNull($value);
                        $this->assertEquals('users.0.name', $attribute);
                        $this->assertEquals(['users.0.name' => 'Taylor Otwell'], $data);

                        return Rule::forEach(function ($value, $attribute, $data) {
                            $this->assertNull($value);
                            $this->assertEquals('users.0.name', $attribute);
                            $this->assertEquals(['users.0.name' => 'Taylor Otwell'], $data);

                            return [Rule::requiredIf(true)];
                        });
                    });
                }),
            ],
        ]);

        $this->assertEquals(['users.0.name' => ['required']], $results->rules);
        $this->assertEquals(['users.*.name' => ['users.0.name']], $results->implicitAttributes);
    }

    public function testExplodeHandlesSegmentingNestedRules()
    {
        $parser = (new ValidationRuleParser([
            'items' => [
                ['discounts' => [['id' => 1], ['id' => 2]]],
                ['discounts' => [['id' => 1], ['id' => 2]]],
            ],
        ]));

        $rules = [
            'items.*' => Rule::forEach(function () {
                return ['discounts.*.id' => 'distinct'];
            }),
        ];

        $results = $parser->explode($rules);

        $this->assertEquals([
            'items.0.discounts.0.id' => ['distinct'],
            'items.0.discounts.1.id' => ['distinct'],
            'items.1.discounts.0.id' => ['distinct'],
            'items.1.discounts.1.id' => ['distinct'],
        ], $results->rules);

        $this->assertEquals([
            'items.1.discounts.*.id' => [
                'items.1.discounts.0.id',
                'items.1.discounts.1.id',
            ],
            'items.0.discounts.*.id' => [
                'items.0.discounts.0.id',
                'items.0.discounts.1.id',
            ],
            'items.*' => [
                'items.0',
                'items.1',
            ],
        ], $results->implicitAttributes);
    }
}
