<?php

namespace Oscmarb\CriteriaMySQLConverter;

use Oscmarb\Criteria\Criteria;
use Oscmarb\Criteria\Filter\Condition\ConditionFilter;
use Oscmarb\Criteria\Filter\Condition\FilterOperator;
use Oscmarb\Criteria\Filter\Filter;
use Oscmarb\Criteria\Filter\Logic\AndFilter;
use Oscmarb\Criteria\Filter\Logic\OrFilter;
use Oscmarb\Criteria\Order\CriteriaOrder;
use Oscmarb\Criteria\Order\CriteriaOrders;
use Oscmarb\Criteria\Order\CriteriaOrderType;
use Oscmarb\Criteria\Pagination\CriteriaLimit;
use Oscmarb\Criteria\Pagination\CriteriaOffset;

class CriteriaMySQLConverter
{
    private string $query;

    /**
     * @param string[]              $fieldsToSelect
     * @param string[]|null         $joins
     * @param array<string, string> $criteriaToMapFields
     */
    public static function create(array $fieldsToSelect, string $tableName, ?array $joins, array $criteriaToMapFields): self
    {
        return new self($fieldsToSelect, $tableName, $joins, $criteriaToMapFields);
    }

    /**
     * @param string[]              $fieldsToSelect
     * @param string[]|null         $joins
     * @param array<string, string> $criteriaToMapFields
     */
    private function __construct(array $fieldsToSelect, string $tableName, ?array $joins, private array $criteriaToMapFields)
    {
        $fieldsToSelect = self::cleanEmptyElements($fieldsToSelect);

        if (true === empty($fieldsToSelect)) {
            throw new \RuntimeException('fieldsToSelect parameter cannot be empty');
        }

        if (true === empty($tableName)) {
            throw new \RuntimeException('tableName parameter cannot be empty');
        }

        $fieldsToSelectString = implode(', ', $fieldsToSelect);

        $this->query = "SELECT $fieldsToSelectString FROM $tableName";

        if (false === empty($joins)) {
            $this->query .= ' '.implode(' ', self::cleanEmptyElements($joins));
        }
    }

    public function convert(Criteria $criteria): string
    {
        $filters = $criteria->filters()->values();

        if (1 >= count($filters)) {
            $filter = array_values($filters)[0] ?? null;
        } else {
            $filter = AndFilter::create(...$filters);
        }

        $orders = $criteria->orders();
        $limit = $criteria->limit();
        $offset = $criteria->offset();

        if (null !== $filter) {
            $this->query .= " WHERE {$this->visitFilter($filter)}";
        }

        if (false === $orders->isEmpty()) {
            $this->query .= " {$this->visitOrders($orders)}";
        }

        if (null !== $limit) {
            $this->query .= " {$this->visitLimit($limit)}";
        }

        if (null !== $offset) {
            $this->query .= " {$this->visitOffset($offset)}";
        }

        return $this->query;
    }

    protected function visitAnd(AndFilter $filter): string
    {
        return ' ( '
            .implode(
                ' AND ',
                array_map(fn(Filter $filter) => $this->visitFilter($filter), $filter->filters())
            )
            .' )';
    }

    protected function visitOr(OrFilter $filter): string
    {
        return ' ( '
            .implode(
                ' OR ',
                array_map(fn(Filter $filter) => $this->visitFilter($filter), $filter->filters())
            )
            .' )';
    }

    protected function visitCondition(ConditionFilter $filter): string
    {
        return " {$this->mapFieldValue($filter->field()->value())} {$this->mapOperator($filter)} {$this->mapParameter($filter)}";
    }

    protected function visitOrders(CriteriaOrders $orders): string
    {
        if (true === $orders->isEmpty()) {
            throw new \RuntimeException('Unexpected empty orders');
        }

        return ' ORDER BY '.implode(
                ', ',
                array_map(
                    fn(CriteriaOrder $order) => "{$this->mapFieldValue($order->orderBy()->value())} {$this->mapOrderType($order->orderType())}",
                    $orders->values()
                )
            );
    }

    protected function visitLimit(CriteriaLimit $limit): string
    {
        return " LIMIT {$limit->value()}";
    }

    protected function visitOffset(CriteriaOffset $offset): string
    {
        return " OFFSET {$offset->value()}";
    }

    protected function visitFilter(Filter $filter): string
    {
        if (true === $filter instanceof AndFilter) {
            return " {$this->visitAnd($filter)}";
        }

        if (true === $filter instanceof OrFilter) {
            return " {$this->visitOr($filter)}";
        }

        if (true === $filter instanceof ConditionFilter) {
            return " {$this->visitCondition($filter)}";
        }

        throw new \RuntimeException('Unknown filter type');
    }

    private function mapParameter(ConditionFilter $filter): string
    {
        $value = $filter->value()->value();

        if (true === $value) {
            return 'TRUE';
        }

        if (false === $value) {
            return 'FALSE';
        }

        if (true === $this->isNullCondition($filter) || true === $this->isNotNullCondition($filter)) {
            return '';
        }

        if (null === $value) {
            throw new \RuntimeException('Unexpected null value');
        }

        if (true === $filter->operator()->isIn() || true === $filter->operator()->isNotIn()) {
            if (false === is_array($value)) {
                throw new \RuntimeException('IN operator should receive an array value');
            }

            return sprintf('( %s )', implode(', ', array_map(static fn($item) => true === is_string($item) ? "\"$item\"" : $item, $value)));
        }

        if (true === is_array($value)) {
            throw new \RuntimeException('Unexpected array value');
        }

        if (true === $filter->operator()->isContains()) {
            return '"%'.$value.'%"';
        }

        if (true === $filter->operator()->isEndsWith()) {
            return '"%'.$value.'"';
        }

        if (true === $filter->operator()->isStartsWith()) {
            return '"'.$value.'%"';
        }

        if (true === is_string($value)) {
            return "\"$value\"";
        }

        return (string) $value;
    }

    private function mapOperator(ConditionFilter $filter): string
    {
        if (true === $this->isNullCondition($filter)) {
            return 'IS NULL';
        }

        if (true === $this->isNotNullCondition($filter)) {
            return 'IS NOT NULL';
        }

        return match ($filter->operator()->value()) {
            FilterOperator::CONTAINS, FilterOperator::STARTS_WITH, FilterOperator::ENDS_WITH => 'LIKE',
            FilterOperator::IN => 'IN',
            FilterOperator::NOT_IN => 'NOT IN',
            default => $filter->operator()->value(),
        };
    }

    private function isNullCondition(ConditionFilter $filter): bool
    {
        return $filter->operator()->isEqual() && null === $filter->value()->value();
    }

    private function isNotNullCondition(ConditionFilter $filter): bool
    {
        return $filter->operator()->isNotEqual() && null === $filter->value()->value();
    }

    private function mapFieldValue(string $value): string
    {
        return \array_key_exists($value, $this->criteriaToMapFields)
            ? $this->criteriaToMapFields[$value]
            : $value;
    }

    private function cleanEmptyElements(array $elements): array
    {
        return array_values(array_filter($elements, static fn($element) => false === empty($element)));
    }

    private function mapOrderType(CriteriaOrderType $orderType): string
    {
        if (true === $orderType->isAsc()) {
            return 'ASC';
        }

        if (true === $orderType->isDesc()) {
            return 'DESC';
        }

        throw new \RuntimeException(sprintf('Unexpected criteria order type: %s', $orderType->value()));
    }
}