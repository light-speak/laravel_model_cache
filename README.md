# Laravel Model Cache

A caching plugin based on Laravel ORM and Redis Cache, relying on queue's model attribute caching

Hold Model instance, proxy fields, use cache modification

## Notice！

Data modification is only applicable to numeric types, and the operation is performed by 1000 times, please pay
attention to the precision

## Install

```shell
composer require light-speak/laravel_model_cache
````

## illustrate

This plug-in all uses Cache. The Redis storage function used by Cache is to proxy a field of an object of a certain
model, and use Cache to maintain it for a period of time (the default is 15 seconds)
All internal modifications are made in the Cache to optimize performance

- Modify the cache for 15 seconds
- Read cache for 3~24 hours

## use

- A queue has been added to the queue, named model-cache, which is used to maintain fields
- Embed in the Model class that needs to be used

```injectablephp
    use ModelCacheTrait;
````

- call the cache() method after the original model

```injectablephp
    $wallet = Wallet::query()->first()->cache();
````

- Start using it normally

## Detailed usage

### Modify data and use plugins for model proxying

```injectablephp
    /**
     * @param bool $useTransaction Whether to use transactions, if true, you must call the saveCache() method to save
     *
     * @return self|CacheModel
     */
    public function cache(bool $useTransaction = false): self|CacheModel
    {
        $this->has_cache = true;
        return ModelCache::make($this, __CLASS__, $useTransaction);
    }
````

Call the cache method of the Model, return a CacheModel instance, and at the same time have the return type of the Model
instance itself, without affecting the IDE prompt

```injectablephp
    $wallet = Wallet::query()->first()->cache();
    
    debug($wallet->balance); // Normal access to Model's field, the first access will store it in the cache
 
````

Use incrementByCache and decrementByCache to modify values

At this time, the regular model Wallet::query()->first()->balance will also get the latest cached data

```injectablephp
    $wallet = Wallet::query()->first()->cache();
    debug("Current wallet amount (check Cache): $wallet->balance"); // 100
    $wallet->incrementByCache('balance', 100);

    $wallet = Wallet::query()->first();
    $wallet->balance = 100;
    debug("Current wallet amount (check DB): $wallet->balance"); // 200
    $wallet->save();
    debug("Current wallet amount (check DB): $wallet->balance"); // 100
    $wallet = Wallet::query()->first()->cache();
    debug("Current wallet amount (check Cache): $wallet->balance"); // 100
````

CacheModel object and Model try to keep consistency

### Transactions

```injectablephp
    $wallet = Wallet::query()->first();
    $wallet->balance = 200;
    $wallet->save();
    
    $wallet1 = Wallet::query()->first()->cache(true); // This must be saved to be useful here
    $wallet1->incrementByCache('balance', 100); // Originally 200 here is an invalid modification


    $wallet = Wallet::query()->first();
    info($wallet->balance); // 200
    
    $wallet = Wallet::query()->first()->cache();
    info($wallet->balance); // 200

    $wallet1 = Wallet::query()->first()->cache(true);
    $wallet1->incrementByCache('balance', 100);
    $wallet1->saveCache(); // It is equal to 100 from here, and it will take effect if it is saved
````