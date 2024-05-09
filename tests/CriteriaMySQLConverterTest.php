<?php

namespace Oscmarb\CriteriaMySQLConverter\Tests;

use Oscmarb\Criteria\CriteriaBuilder;
use Oscmarb\Criteria\Filter\Condition\ConditionFilterFactory;
use Oscmarb\Criteria\Filter\Logic\OrFilter;
use Oscmarb\CriteriaMySQLConverter\CriteriaMySQLConverter;
use PHPUnit\Framework\TestCase;

final class CriteriaMySQLConverterTest extends TestCase
{
    public function testShouldConvertCriteriaToSqlQuery(): void
    {
        $fieldMappings = [
            'childField' => 'first_child_table.field',
            'secondChildField' => 'second_child_table.field',
            'oldOrder' => 'newOrder',
        ];
        $converter = CriteriaMySQLConverter::create(
            ['field', 'otherField'],
            'parent_table',
            [
                'INNER JOIN first_child_table ON parent_table.id = child_table.id',
                'LEFT JOIN second_child_table ON parent_table.id = child_table.id',
            ],
            $fieldMappings
        );

        $expectedQuery = 'SELECT field, otherField
FROM parent_table 
INNER JOIN first_child_table ON parent_table.id = child_table.id 
LEFT JOIN second_child_table ON parent_table.id = child_table.id 
WHERE (
    (
        
        first_child_table.field = "value"
        OR
        second_child_table.field IS NULL
    ) 
    AND newOrder IS NOT NULL
    AND value IN ( "1", "2" )
    AND value NOT IN ( 1, 2 )
    AND value LIKE "%value%"
    AND value LIKE "value%"
    AND value LIKE "%value"
    AND value = "value"
    AND value != "value"
    AND value > 1
    AND value >= 1
    AND value < 1
    AND value <= 1
) 
ORDER BY newOrder ASC, regularField DESC 
LIMIT 20 
OFFSET 5';

        $criteria = CriteriaBuilder::create()
            ->addFilter(
                OrFilter::create(
                    ConditionFilterFactory::createEqual('childField', 'value'),
                    ConditionFilterFactory::createEqual('secondChildField', null),
                )
            )
            ->addFilter(ConditionFilterFactory::createNotEqual('oldOrder', null))
            ->addFilter(ConditionFilterFactory::createIn('value', ['1', '2']))
            ->addFilter(ConditionFilterFactory::createNotIn('value', [1, 2]))
            ->addFilter(ConditionFilterFactory::createContains('value', 'value'))
            ->addFilter(ConditionFilterFactory::createStartsWith('value', 'value'))
            ->addFilter(ConditionFilterFactory::createEndsWith('value', 'value'))
            ->addFilter(ConditionFilterFactory::createEqual('value', 'value'))
            ->addFilter(ConditionFilterFactory::createNotEqual('value', 'value'))
            ->addFilter(ConditionFilterFactory::createGt('value', 1))
            ->addFilter(ConditionFilterFactory::createGte('value', 1))
            ->addFilter(ConditionFilterFactory::createLt('value', 1))
            ->addFilter(ConditionFilterFactory::createLte('value', 1))
            ->addAscOrder('oldOrder')
            ->addDescOrder('regularField')
            ->setLimit(20)
            ->setOffset(5)
            ->createCriteria();

        self::assertEquals(self::sanitizeSql($expectedQuery), self::sanitizeSql($converter->convert($criteria)));
    }

    private static function sanitizeSql(string $sql): string
    {
        $sql = str_replace(["\n", "\t"], ' ', $sql);

        while (true === str_contains($sql, '  ')) {
            $sql = str_replace('  ', ' ', $sql);
        }

        return $sql;
    }
}