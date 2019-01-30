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

        /**
         * @todo: allow passing associative arrays (or fluent QB calls), instead of if/else calls below.
         * e.g. $relations['primary_table'], $relations['poly_key'], etc.
         * BUT if need to provide a full associative array for each, is that better than QB?
         * Sortable too limited without fluent calls (or even with) for normal/flipped/pivot/poly/etc?
         */

        if (method_exists($builder->getModel(), $sortMethod)) {
            call_user_func([$builder->getModel(), $sortMethod], $builder, $this->getOrder());
        } else if (false !== strpos($this->getField(), '.')) {
            $relation_keys = explode('.', $this->getField());

            if (in_array('flip', $relation_keys, true)) {
                // .flip means the FK lives on the main table, not the join table.
                if (isset($relation_keys[4]) && $relation_keys[4] === 'flip') {

                    // Flipped Join, FK on local table:  main_table[0].join_table[1].foreign_key[2].sort_by_field[3].flip[4]
                    if (! collect($builder->getQuery()->joins)->pluck('table')->contains($relation_keys[1])) {
                        $builder->leftJoin($relation_keys[1], $relation_keys[0] . '.' . $relation_keys[2], '=', $relation_keys[1] . '.id');
                    }
                    $builder->orderBy($relation_keys[1] . '.' . $relation_keys[3], $this->getOrder())
                        ->select($relation_keys[0] . '.*');       // just to avoid fetching anything from joined table
                } elseif (isset($relation_keys[6]) && $relation_keys[6] === 'flip') {

                    // Polymorphic Relationships:  main_table[0].poly_table[1].poly_foreign_key[2].join_table[3].foreign_key[4].sort_by_field[5].flip[6]
                    if (! collect($builder->getQuery()->joins)->pluck('table')->contains($relation_keys[1])) {
                        $builder->leftJoin($relation_keys[1], $relation_keys[0] . '.' . $relation_keys[2], '=', $relation_keys[1] . '.id');
                    }
                    if (! collect($builder->getQuery()->joins)->pluck('table')->contains($relation_keys[3])) {
                        // use foreign key in primary table
                        $builder->leftJoin($relation_keys[3], $relation_keys[1] . '.' . $relation_keys[4], '=', $relation_keys[3] . '.id');
                    }
                    $builder->orderBy($relation_keys[3] . '.' . $relation_keys[5], $this->getOrder())
                        ->select($relation_keys[0] . '.*');       // just to avoid fetching anything from joined table
                } elseif (isset($relation_keys[9]) && $relation_keys[9] === 'flip') {
                    if (isset($relation_keys[4]) && $relation_keys[4] === 'pivot') {
                        // M2M Sort, with Pivot:  main_table[0].join_table[1].foreign_key[2].sort_by_field[3].pivot[4].pivot_table[5].local_key[6]
                        $pivotTable = $relation_keys[5];
                        $localKey = $relation_keys[6];

                        if (! collect($builder->getQuery()->joins)->pluck('table')->contains($relation_keys[1])) {
                            $builder->leftJoin($pivotTable, $pivotTable . '.' . $localKey, '=', $relation_keys[0] . '.id');
                            $builder->leftJoin($relation_keys[1], $pivotTable . '.' . $relation_keys[2], '=', $relation_keys[1] . '.id');
                        }
                    }

                    $fk = $relation_keys[3];
                    $joinTable = $relation_keys[7];
                    $sortColumn = $relation_keys[8];

                    if (! collect($builder->getQuery()->joins)->pluck('table')->contains($joinTable)) {
                        $builder->leftJoin($joinTable, $joinTable . '.id', '=', $relation_keys[1] . '.' . $fk);
                    }

                    $builder->orderBy($relation_keys[7] . '.' . $relation_keys[8], $this->getOrder())
                        ->select($relation_keys[0] . '.*');
                }
            } elseif (isset($relation_keys[4]) && $relation_keys[4] === 'pivot') {

                // M2M Sort, with Pivot:  main_table[0].join_table[1].foreign_key[2].sort_by_field[3].pivot[4].pivot_table[5].local_key[6]
                $pivotTable = $relation_keys[5];
                $localKey = $relation_keys[6];

                if (! collect($builder->getQuery()->joins)->pluck('table')->contains($relation_keys[1])) {
                    $builder->leftJoin($pivotTable, $pivotTable . '.' . $localKey, '=', $relation_keys[0] . '.id');
                    $builder->leftJoin($relation_keys[1], $pivotTable . '.' . $relation_keys[2], '=', $relation_keys[1] . '.id');
                }
                $builder->orderBy($relation_keys[1] . '.' . $relation_keys[3], $this->getOrder())
                    ->select($relation_keys[0] . '.*');
            } else {

                // Normal Join, FK on related table:  main_table[0].join_table[1].foreign_key[2].sort_by_field[3]
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
