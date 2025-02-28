<?php

namespace Laravel\Scout\Engines;

use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use MeiliSearch\Client as MeiliSearchClient;
use MeiliSearch\MeiliSearch;
use MeiliSearch\Search\SearchResult;

class MeiliSearchEngine extends Engine
{
    /**
     * The MeiliSearch client.
     *
     * @var \MeiliSearch\Client
     */
    protected $meilisearch;

    /**
     * Determines if soft deletes for Scout are enabled or not.
     *
     * @var bool
     */
    protected $softDelete;

    /**
     * Create a new MeiliSearchEngine instance.
     *
     * @param  \MeiliSearch\Client  $meilisearch
     * @param  bool  $softDelete
     * @return void
     */
    public function __construct(MeiliSearchClient $meilisearch, $softDelete = false)
    {
        $this->meilisearch = $meilisearch;
        $this->softDelete = $softDelete;
    }

    /**
     * Update the given model in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     *
     * @throws \MeiliSearch\Exceptions\ApiException
     */
    public function update($models)
    {
        if ($models->isEmpty()) {
            return;
        }


        if ($this->usesSoftDelete($models->first()) && $this->softDelete) {
            $models->each->pushSoftDeleteMetadata();
        }

        $models = $models->mapToGroups(function ($model) {
            // The index the item needs to be pushed to
            $index = $model->searchableAs();

            return [$index => $model];
        });

        // All models are grouped by index now.
        // Loop over the different indexes and push them to the search engine
        $models->each(function ($items, $index) {
            $objects = $items->map(function ($model) {
                if (empty($searchableData = $model->toSearchableArray())) {
                    return;
                }


                // The data that needs to be pushed to Meili
                return  array_merge(
                    [$model->getKeyName() => $model->getScoutKey()],
                    $searchableData,
                    $model->scoutMetadata()
                );
            })->filter()->values()->all();

            if (! empty($items)) {
                // Generate index object from the name
                $index = $this->meilisearch->index($index);

                $index->addDocuments($objects, $items->first()->getKeyName());
            }
        });
    }

    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function delete($models)
    {
        $index = $this->meilisearch->index($models->first()->searchableAs());

        $index->deleteDocuments(
            $models->map->getScoutKey()
                ->values()
                ->all()
        );
    }

    /**
     * Perform the given search on the engine.
     *
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder, array_filter([
            'filters' => $this->filters($builder),
            'limit' => $builder->limit,
            'sort' => $this->buildSortFromOrderByClauses($builder),
        ]));
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  int  $perPage
     * @param  int  $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->performSearch($builder, array_filter([
            'filters' => $this->filters($builder),
            'limit' => (int) $perPage,
            'offset' => ($page - 1) * $perPage,
            'sort' => $this->buildSortFromOrderByClauses($builder),
        ]));
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  array  $searchParams
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $searchParams = [])
    {
        $meilisearch = $this->meilisearch->index($builder->index ?: $builder->model->searchableAs());

        // meilisearch-php 0.19.0 is compatible with meilisearch server 0.21.0...
        if (version_compare(MeiliSearch::VERSION, '0.19.0') >= 0 && isset($searchParams['filters'])) {
            $searchParams['filter'] = $searchParams['filters'];

            unset($searchParams['filters']);
        }

        if ($builder->callback) {
            $result = call_user_func(
                $builder->callback,
                $meilisearch,
                $builder->query,
                $searchParams
            );

            return $result instanceof SearchResult ? $result->getRaw() : $result;
        }

        return $meilisearch->rawSearch($builder->query, $searchParams);
    }

    /**
     * Get the filter array for the query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return string
     */
    protected function filters(Builder $builder)
    {
        $filters = collect($builder->wheres)->map(function ($value, $key) {
            if (is_bool($value)) {
                return sprintf('%s=%s', $key, $value ? 'true' : 'false');
            }

            return is_numeric($value)
                            ? sprintf('%s=%s', $key, $value)
                            : sprintf('%s="%s"', $key, $value);
        });

        foreach ($builder->whereIns as $key => $values) {
            $filters->push(sprintf('(%s)', collect($values)->map(function ($value) use ($key) {
                if (is_bool($value)) {
                    return sprintf('%s=%s', $key, $value ? 'true' : 'false');
                }

                return filter_var($value, FILTER_VALIDATE_INT) !== false
                                ? sprintf('%s=%s', $key, $value)
                                : sprintf('%s="%s"', $key, $value);
            })->values()->implode(' OR ')));
        }

        return $filters->values()->implode(' AND ');
    }

    /**
     * Get the sort array for the query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return array
     */
    protected function buildSortFromOrderByClauses(Builder $builder): array
    {
        return collect($builder->orders)->map(function (array $order) {
            return $order['column'].':'.$order['direction'];
        })->toArray();
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * This expects the first item of each search item array to be the primary key.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        if (0 === count($results['hits'])) {
            return collect();
        }

        $hits = collect($results['hits']);

        $key = key($hits->first());

        return $hits->pluck($key)->values();
    }

    /**
     * Pluck and the given results with the given primary key name.
     *
     * @param  mixed  $results
     * @param  string  $key
     * @return \Illuminate\Support\Collection
     */
    public function mapIdsFrom($results, $key)
    {
        return count($results['hits']) === 0
                ? collect()
                : collect($results['hits'])->pluck($key)->values();
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        if (is_null($results) || 0 === count($results['hits'])) {
            return $model->newCollection();
        }

        $objectIds = collect($results['hits'])->pluck($model->getKeyName())->values()->all();

        $objectIdPositions = array_flip($objectIds);

        return $model->getScoutModelsByIds(
            $builder,
            $objectIds
        )->filter(function ($model) use ($objectIds) {
            return in_array($model->getScoutKey(), $objectIds);
        })->sortBy(function ($model) use ($objectIdPositions) {
            return $objectIdPositions[$model->getScoutKey()];
        })->values();
    }

    /**
     * Map the given results to instances of the given modell via a lazy collection.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Support\LazyCollection
     */
    public function lazyMap(Builder $builder, $results, $model)
    {
        if (count($results['hits']) === 0) {
            return LazyCollection::make($model->newCollection());
        }

        $objectIds = collect($results['hits'])->pluck($model->getKeyName())->values()->all();
        $objectIdPositions = array_flip($objectIds);

        return $model->queryScoutModelsByIds(
            $builder,
            $objectIds
        )->cursor()->filter(function ($model) use ($objectIds) {
            return in_array($model->getScoutKey(), $objectIds);
        })->sortBy(function ($model) use ($objectIdPositions) {
            return $objectIdPositions[$model->getScoutKey()];
        })->values();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed  $results
     * @return int
     */
    public function getTotalCount($results)
    {
        return $results['nbHits'];
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function flush($model)
    {
        $index = $this->meilisearch->index($model->searchableAs());

        $index->deleteAllDocuments();
    }

    /**
     * Create a search index.
     *
     * @param  string  $name
     * @param  array  $options
     * @return mixed
     *
     * @throws \MeiliSearch\Exceptions\ApiException
     */
    public function createIndex($name, array $options = [])
    {
        return $this->meilisearch->createIndex($name, $options);
    }

    /**
     * Delete a search index.
     *
     * @param  string  $name
     * @return mixed
     *
     * @throws \MeiliSearch\Exceptions\ApiException
     */
    public function deleteIndex($name)
    {
        return $this->meilisearch->deleteIndex($name);
    }

    /**
     * Determine if the given model uses soft deletes.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    protected function usesSoftDelete($model)
    {
        return in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model));
    }

    /**
     * Dynamically call the MeiliSearch client instance.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->meilisearch->$method(...$parameters);
    }
}
