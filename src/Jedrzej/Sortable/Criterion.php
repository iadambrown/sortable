<?php namespace Jedrzej\Sortable;

use function call_user_func, in_array;

use InvalidArgumentException;
use Illuminate\Database\Eloquent\Builder;

class Criterion
{
    public const ORDER_ASCENDING = 'asc';
    public const ORDER_DESCENDING = 'desc';

    protected $field;

    protected $order;

    /**
     * @return string
     */
    public function getField(): string
    {
        return $this->field;
    }

    /**
     * @return string
     */
    public function getOrder(): string
    {
        return $this->order;
    }

    /**
     * Creates criterion object for given value.
     *
     * @param string $value query value
     * @param string $defaultOrder default sort order if order is not given explicitly in query
     *
     * @return Criterion
     */
    public static function make($value, $defaultOrder = self::ORDER_ASCENDING): Criterion
    {
        $value = static::prepareValue($value);
        [$field, $order] = static::parseFieldAndOrder($value, $defaultOrder);

        return new static($field, $order);
    }

    /**
     * Applies criterion to query.
     *
     * @param Builder $builder query builder
     */
    public function apply(Builder $builder): void
    {
        $sortMethod = 'sort' . studly_case($this->getField());

        if (method_exists($builder->getModel(), $sortMethod)) {
            call_user_func([$builder->getModel(), $sortMethod], $builder, $this->getOrder());
        } else if (false !== strpos($this->getField(), '.')) {
            $relation_keys = explode('.', $this->getField());
            if (isset($relation_keys[4]) && $relation_keys[4] === 'flip') {
                // main table[0] . join_table[1] . foreign_key[2] . sort_column[3] . (literal word) flip[4]
                if (! collect($builder->getQuery()->joins)->pluck('table')->contains($relation_keys[1])) {
                    // use foreign key in primary table
                    $builder->leftJoin($relation_keys[1], $relation_keys[0] . '.' . $relation_keys[2], '=', $relation_keys[1] . '.id');
                }
                $builder->orderBy($relation_keys[1] . '.' . $relation_keys[3], $this->getOrder())
                    ->select($relation_keys[0] . '.*');       // just to avoid fetching anything from joined table
            } elseif (isset($relation_keys[4]) && $relation_keys[4]==='pivot') {
                // main_table [0] . join_table [1] . foreign_key [2] . sort_column [3] . (literal word) pivot [4] . pivot_table [5] . local_key [6]
                $pivotTable = $relation_keys[5];
                $localKey = $relation_keys[6];

                if (! collect($builder->getQuery()->joins)->pluck('table')->contains($relation_keys[1])) {
                    $builder->leftJoin($pivotTable, $pivotTable . '.' . $localKey, '=', $relation_keys[0] . '.id');
                    $builder->leftJoin($relation_keys[1], $pivotTable . '.' . $relation_keys[2], '=', $relation_keys[1] . '.id');
                }
                $builder->orderBy($relation_keys[1] . '.' . $relation_keys[3], $this->getOrder())
                    ->select($relation_keys[0] . '.*');
            } else {
                // use foreign key in joined table
                if (! collect($builder->getQuery()->joins)->pluck('table')->contains($relation_keys[1])) {
                    $builder->leftJoin($relation_keys[1], $relation_keys[1] . '.' . $relation_keys[2], '=', $relation_keys[0] . '.id');
                }
                $builder->orderBy($relation_keys[1] . '.' . $relation_keys[3], $this->getOrder())
                    ->select($relation_keys[0] . '.*');       // just to avoid fetching anything from joined table
            }
        } else {
            $builder->orderBy($this->getField(), $this->getOrder());
        }
    }

    /**
     * @param string $field field name
     * @param string $order sort order
     */
    protected function __construct($field, $order)
    {
        if (! in_array($order, [static::ORDER_ASCENDING, static::ORDER_DESCENDING], true)) {
            throw new InvalidArgumentException('Invalid order value');
        }

        $this->field = $field;
        $this->order = $order;
    }

    /**
     *  Cleans value and converts to array if needed.
     *
     * @param string $value value
     *
     * @return string
     */
    protected static function prepareValue($value): string
    {
        return trim($value, " \t\n\r\0\x0B");
    }

    /**
     * Parse query parameter and get field name and order.
     *
     * @param string $value
     * @param string $defaultOrder default sort order if order is not given explicitly in query
     *
     * @return string[]
     *
     * @throws InvalidArgumentException when unable to parse field name or order
     */
    protected static function parseFieldAndOrder($value, $defaultOrder): array
    {
        if (preg_match('/^([^,]+)(,(asc|desc))?$/', $value, $match)) {
            return [$match[1], $match[3] ?? $defaultOrder];
        }

        throw new InvalidArgumentException(sprintf('Unable to parse field name or order from "%s"', $value));
    }
}
