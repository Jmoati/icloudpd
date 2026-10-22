<?php

namespace Tests;

use Foundation\Core;
use Foundation\Json;

class JsonRulesTest extends TestCase
{
    /** `list(map(lambda p: (p, constant(value)), compile_patterns([pattern])))` */
    private static function rules(string $pattern, mixed $value): array
    {
        return iterator_to_array(Core::map_(fn ($p) => [$p, Core::constant($value)], Json::compile_patterns([$pattern])), false);
    }

    public function test_str_match(): void
    {
        $input = 'abc';
        $context = 'def';
        $rules = self::rules('de.*', 'hij');
        $result = Json::apply_rules($context, $rules, $input);
        $this->assertSame('hij', $result, 'match');
    }

    public function test_str_not_match(): void
    {
        $input = 'abc';
        $context = 'def';
        $rules = self::rules('ae.*', 'hij');
        $result = Json::apply_rules($context, $rules, $input);
        $this->assertSame('abc', $result, 'not match');
    }

    public function test_str_match_for_none(): void
    {
        $input = 'abc';
        $context = 'def';
        $rules = self::rules('de.*', null);
        $result = Json::apply_rules($context, $rules, $input);
        $this->assertNull($result, 'match for None');
    }

    public function test_tuple_match(): void
    {
        $input = \SplFixedArray::fromArray(['abc', 'def']); // a tuple
        $rules = self::rules('abc.*', 'lmn');
        $result = Json::apply_rules('', $rules, $input);
        $this->assertSame(['abc', 'lmn'], $result->toArray(), 'tuple match');
    }

    public function test_list_match(): void
    {
        $input = ['abc', 'def'];
        $rules = self::rules('.*', 'lmn');
        $result = Json::apply_rules('', $rules, $input);
        $this->assertSame('lmn', $result, 'list match');
    }

    public function test_list_match_each(): void
    {
        $input = ['abc', 'def'];
        $rules = self::rules('\..*', 'lmn');
        $result = Json::apply_rules('', $rules, $input);
        $this->assertSame(['lmn', 'lmn'], $result, 'list match');
    }

    public function test_json_match(): void
    {
        $input = ['abc' => ['def' => 'hij']];
        $rules = self::rules('abc\.def.*', 'lmn');
        $result = Json::apply_rules('', $rules, $input);
        $this->assertSame(['abc' => ['def' => 'lmn']], $result, 'json match');
    }

    public function test_json_match_by_leaf(): void
    {
        $input = ['abc' => ['def' => 'hij']];
        $rules = self::rules('def.*', 'lmn');
        $result = Json::apply_rules('', $rules, $input);
        $this->assertSame(['abc' => ['def' => 'lmn']], $result, 'json match');
    }

    public function test_json_update_object_in_list(): void
    {
        $input = ['abc' => [['def' => 'hij']]];
        $rules = self::rules('^abc\.def$', 'lmn');
        $result = Json::apply_rules('', $rules, $input);
        $this->assertSame(['abc' => [['def' => 'lmn']]], $result, 'json match');
    }

    public function test_json_drop_object_in_list(): void
    {
        $input = ['abc' => [['def' => 'hij']]];
        $rules = self::rules('^abc\.$', null);
        $result = Json::apply_rules('', $rules, $input);
        $this->assertSame(['abc' => []], $result, 'json match');
    }

    public function test_json_dict_drop(): void
    {
        $input = ['abc' => ['def' => 'hij']];
        $rules = self::rules('abc\.def.*', null);
        $result = Json::apply_rules('', $rules, $input);
        $this->assertSame(['abc' => []], $result, 'json dict drop'); // {} is [] in PHP
    }

    public function test_json_list_drop(): void
    {
        $input = ['abc' => ['def' => ['hij']]];
        $rules = self::rules('abc\.def\..*', null);
        $result = Json::apply_rules('', $rules, $input);
        $this->assertSame(['abc' => ['def' => []]], $result, 'json list drop');
    }
}
