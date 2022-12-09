<?php

namespace Laravel\Feature\Drivers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Feature\Contracts\Driver;
use Laravel\Feature\Events\RetrievingKnownFeature;
use Laravel\Feature\Events\RetrievingUnknownFeature;

class DatabaseDriver implements Driver
{
    /**
     * The database connection.
     *
     * @var \Illuminate\Database\Connection
     */
    protected $db;

    /**
     * The event dispatcher.
     *
     * @var \Illuminate\Contracts\Events\Dispatcher
     */
    protected $events;

    /**
     * The feature state resolvers.
     *
     * @var array<string, (callable(mixed): mixed)>
     */
    protected $featureStateResolvers;

    /**
     * The scope comparator.
     *
     * @var (callable(mixed, mixed, string): bool)
     */
    protected $scopeComparator;

    /**
     * Create a new driver instance.
     *
     * @param  \Illuminate\Database\Connection  $db
     * @param  \Illuminate\Contracts\Events\Dispatcher  $events
     * @param  (callable(mixed, mixed, string): bool)  $scopeComparator
     * @param  array<string, (callable(mixed $scope): mixed)>  $featureStateResolvers
     */
    public function __construct(Connection $db, Dispatcher $events, $scopeComparator, $featureStateResolvers)
    {
        $this->db = $db;

        $this->events = $events;

        $this->scopeComparator = $scopeComparator;

        $this->featureStateResolvers = $featureStateResolvers;
    }

    /**
     * Retrieve the flags value.
     *
     * @param  string  $feature
     * @param  mixed  $scope
     * @return bool
     */
    public function get($feature, $scope)
    {
        $record = $this->retrieve($feature, $scope);

        if ($record !== null) {
            return unserialize($record->value);
        }

        return tap($this->resolveValue($feature, $scope), function ($value) use ($feature, $scope) {
            $this->insert($feature, $scope, $value);
        });
    }

    /**
     * Set the flags value.
     *
     * @param  string  $feature
     * @param  mixed  $scope
     * @param  mixed  $value
     * @return void
     */
    public function set($feature, $scope, $value)
    {
        if (! $this->update($feature, $scope, $value)) {
            $this->insert($feature, $scope, $value);
        }
    }

    /**
     * Register an initial flag state resolver.
     *
     * @param  string  $feature
     * @param  (callable(mixed $scope): mixed)  $resolver
     * @return void
     */
    public function register($feature, $resolver)
    {
        $this->featureStateResolvers[$feature] = $resolver;
    }

    /**
     * Retrieve mutliple flags values.
     *
     * @param  array<string, array<int, mixed>>  $features
     * @return array<string, array<int, mixed>>
     */
    public function load($features)
    {
        $inserts = new Collection;
        $query = $this->db->table('features');

        $features = Collection::make($features)
            ->map(fn ($scopes, $feature) => Collection::make($scopes)
                ->each(fn ($scope) => $query->orWhere(fn ($q) => $q->where('name', $feature)->where('scope', $scope))));

        $records = $query->get();

        $results = $features->map(fn ($scopes, $feature) => $scopes->map(function ($scope) use ($feature, $records, $inserts) {
            if ($records->where('name', $feature)->where('scope', $scope)->isNotEmpty()) {
                return unserialize($records->where('name', $feature)->where('scope', $scope)->value('value'));
            }

            return tap($this->resolveValue($feature, $scope), function ($value) use ($feature, $scope, $inserts) {
                $inserts[] = [
                    'name' => $feature,
                    'scope' => $scope,
                    'value' => serialize($value),
                ];
            });
        })->all())->all();

        if ($inserts->isNotEmpty()) {
            $this->db->table('features')->insert($inserts->all());
        }

        return $results;
    }

    /**
     * Retrieve the value in storage.
     *
     * @param  string  $feature
     * @param  mixed  $scope
     * @return object|null
     */
    protected function retrieve($feature, $scope)
    {
        return $this->db->table('features')
            ->where('name', '=', $feature)
            ->where('scope', '=', $scope)
            ->first();
    }

    /**
     * Insert the value in storage.
     *
     * @param  string  $feature
     * @param  mixed  $scope
     * @param  mixed  $value
     * @return bool
     */
    protected function insert($feature, $scope, $value)
    {
        return $this->db->table('features')->insert([
            'name' => $feature,
            'scope' => $scope,
            'value' => serialize($value),
        ]);
    }

    /**
     * Update the value in storage.
     *
     * @param  string  $feature
     * @param  mixed  $scope
     * @param  mixed  $value
     * @return bool
     */
    protected function update($feature, $scope, $value)
    {
        return $this->db->table('features')
            ->where('name', $feature)
            ->where('scope', $scope)
            ->update([
                'value' => serialize($value),
            ]) > 0;
    }

    /**
     * Determine the initial feature value.
     *
     * @param  string  $feature
     * @param  mixed  $scope
     * @return mixed
     */
    protected function resolveValue($feature, $scope)
    {
        if (! array_key_exists($feature, $this->featureStateResolvers)) {
            $this->events->dispatch(new RetrievingUnknownFeature($feature, $scope));

            return false;
        }

        return tap($this->featureStateResolvers[$feature]($scope), function ($value) use ($feature, $scope) {
            $this->events->dispatch(new RetrievingKnownFeature($feature, $scope, $value));

            return $value;
        });
    }

    /**
     * Determine if the features are active for the given scope.
     *
     * @param  array<int, string>  $features
     * @param  array<int, mixed>  $scope
     * @return bool
     */
    //public function isActive($features, $scope = [])
    //{
    //    $resolved = $this->resolve($features, $scope);

    //    $this->cacheMissing($resolved);

    //    return $resolved->every(function ($feature) {
    //        $record = $this->cache->where('name', $feature['name'])->where('scope', $feature['key'])->first();

    //        if ($record !== null) {
    //            return $record->is_active;
    //        }

    //        if ($this->missingResolver($feature['name'])) {
    //            $this->events->dispatch(new CheckingUnknownFeature($feature['name'], $feature['scope'] ?? null));

    //            return false;
    //        }

    //        $isActive = $this->resolveInitialFeatureState($feature['name'], $feature['scope']);

    //        $this->db->table('features')->insert([
    //            'name' => $feature['name'],
    //            'scope' => $feature['key'],
    //            'is_active' => $isActive,
    //        ]);

    //    });
    //}

    //protected function cacheMissing($resolved)
    //{
    //    $missing = $resolved->reject(fn ($feature) => $this->isCached($feature));

    //    if ($missing->isEmpty()) {
    //        return;
    //    }

    //    $this->cache = $this->cache->merge($this->fetch($missing));
    //}

    //protected function fetch($resolved)
    //{
    //    return tap($this->db->table('features'), function ($query) use ($resolved) {
    //        $resolved->each(fn ($feature) => $query->where('name', $feature['name'])->where('scope', $feature['key']));
    //    })->get();
    //}

    //protected function isCached($feature)
    //{
    //    return $this->cache->contains(
    //        fn ($f) => $f->name === $feature['name'] && $f->scope === $feature['key']
    //    );
    //}

    ///**
    // * Activate the features for the given scope.
    // *
    // * TODO caching
    // *
    // * @param  array<int, string>  $features
    // * @param  array<int, mixed>  $scope
    // * @return void
    // */
    //public function activate($features, $scope = [])
    //{
    //    $resolved = $this->resolve($features, $scope);

    //    $existing = $this->fetch($resolved);

    //    $resolved->each(function ($feature) use ($existing) {
    //        $record = $existing->where('name', $feature['name'])->where('scope', $feature['key'])->first();

    //        if ($record !== null && $record->is_active !== false) {
    //            return;
    //        }

    //        if (! $record) {
    //            $this->db->table('features')->insert([
    //                'name' => $feature['name'],
    //                'scope' => $feature['key'],
    //                'is_active' => true,
    //            ]);

    //            return;
    //        }

    //        $this->db->table('features')
    //            ->where('id', $record->id)
    //            ->update([
    //                'is_active' => true,
    //            ]);
    //    })->each(function ($feature) {
    //        $this->cache([
    //            ...$feature,
    //            'is_active' => true,
    //        ]);
    //    });

    //    //
    //    // cache.
    //    // $this->cache = $this->cache->merge(
    //    //     $this->resolve($features, $scope)
    //    //         ->mapWithKeys(fn ($resolved) => [
    //    //             $resolved['key'] => true,
    //    //         ])
    //    // );
    //}

    //protected function cache($feature)
    //{
    //    $this->cache = $this->cache->reject(
    //        fn ($f) => $f->name === $feature['name'] && $f->scope === $feature['key']
    //    )->push((object) $feature)->values();
    //}

    ///**
    // * Deactivate the features for the given scope.
    // *
    // * TODO caching
    // *
    // * @param  array<int, string>  $features
    // * @param  array<int, mixed>  $scope
    // * @return void
    // */
    //public function deactivate($features, $scope = [])
    //{
    //    $resolved = $this->resolve($features, $scope);

    //    $existing = $this->fetch($resolved);

    //    $resolved->each(function ($feature) use ($existing) {
    //        $record = $existing->where('name', $feature['name'])->where('scope', $feature['key'])->first();

    //        if ($record !== null && $record->is_active === false) {
    //            return;
    //        }

    //        if (! $record) {
    //            $this->db->table('features')->insert([
    //                'name' => $feature['name'],
    //                'scope' => $feature['key'],
    //                'is_active' => false,
    //            ]);

    //            return;
    //        }

    //        $this->db->table('features')
    //            ->where('id', $record->id)
    //            ->update([
    //                'is_active' => false,
    //            ]);
    //    })->each(function ($feature) {
    //        $this->cache([
    //            ...$feature,
    //            'is_active' => false,
    //        ]);
    //    });
    //}

    ///**
    // * Register an initial feature state resolver.
    // *
    // * @param  string  $feature
    // * @param  (callable(mixed $scope): mixed)  $resolver
    // * @return void
    // */
    //public function register($feature, $resolver)
    //{
    //    $this->initialFeatureStateResolvers[$feature] = $resolver;
    //}

    ///**
    // * Determine if the feature has no resolver available.
    // *
    // * @param  string  $feature
    // * @return bool
    // */
    //protected function missingResolver($feature)
    //{
    //    return ! array_key_exists($feature, $this->initialFeatureStateResolvers);
    //}

    ///**
    // * Resolve a features initial state.
    // *
    // * @param  string  $feature
    // * @param  mixed  $scope
    // * @return bool
    // */
    //protected function resolveInitialFeatureState($feature, $scope)
    //{
    //    return $this->initialFeatureStateResolvers[$feature]($scope) !== false;
    //}

    ///**
    // * Resolve all permutations of the features and scope keys.
    // *
    // * @param  array<int, string>  $features
    // * @param  array<int, mixed>  $scope
    // * @return \Illuminate\Support\Collection<int, array{ name: string, scope: mixed, key: string }>
    // */
    //protected function resolve($features, $scope)
    //{
    //    if ($scope === []) {
    //        return Collection::make($features)->map(fn ($feature) => [
    //            'name' => $feature,
    //            'scope' => null,
    //            'key' => null,
    //        ]);
    //    }

    //    return Collection::make($scope)
    //        ->crossJoin($features)
    //        ->mapSpread(fn ($scope, $feature) => [
    //            'name' => $feature,
    //            'scope' => $scope,
    //            'key' => $this->resolveKey($scope),
    //        ]);
    //}

    ///**
    // * Resolve the key for the given scope.
    // *
    // * @param  mixed  $scope
    // * @return string|null
    // */
    //protected function resolveKey($scope)
    //{
    //    if ($scope === null) {
    //        return null;
    //    }

    //    if ($scope instanceof FeatureScopeable) {
    //        return (string) $scope->toFeatureScopeIdentifier();
    //    }

    //    if ($scope instanceof Model) {
    //        return 'eloquent_model:'.(new $scope)->getMorphClass().':'.$scope->getKey();
    //    }

    //    return (string) $scope;
    //}
}
