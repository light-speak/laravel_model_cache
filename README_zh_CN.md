# Laravel Model Cache

一个基于Laravel ORM 和Redis Cache的缓存插件，依赖queue的模型属性缓存

持有Model实例，代理字段，使用缓存修改

## 重点

数据修改只适用于数字类型，以1000倍进行运算，请注意精度

## 安装

```shell
composer require light-speak/laravel_model_cache
```

## 说明

本插件全部用的Cache， Cache使用的Redis存储 功能为，对某个模型的对象的某个字段进行代理，使用Cache维护 一段时间(默认为15秒)
内的修改全部在Cache进行, 以此优化性能

- 修改缓存15秒
- 读取缓存3~24小时

## 使用

- 队列新增一个queue，名称为：model-cache ，用于维护字段
- 在需要使用的Model类嵌入

```injectablephp
    use ModelCacheTrait;
```

- 在原来的模型后面调用cache()方法

```injectablephp
    $wallet = Wallet::query()->first()->cache();
```

- 开始正常使用吧

## 详细使用

### 修改数据，并使用插件进行模型代理

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
```

调用Model的cache方法，返回一个CacheModel实例，同时拥有Model实例本身返回类型，不影响IDE提示

```injectablephp
    $wallet = Wallet::query()->first()->cache();
    
    debug($wallet->balance);   // 正常访问Model的field，第一次访问会将之存入缓存
 
```

使用 incrementByCache 和 decrementByCache 进行修改数值

此时常规模型 Wallet::query()->first()->balance 也会获取到最新的缓存数据

```injectablephp
    $wallet = Wallet::query()->first()->cache();
    debug("当前钱包金额（查Cache）: $wallet->balance"); // 100
    $wallet->incrementByCache('balance', 100);

    $wallet = Wallet::query()->first();
    $wallet->balance = 100;
    debug("当前钱包金额（查DB）: $wallet->balance"); // 200
    $wallet->save();
    debug("当前钱包金额（查DB）: $wallet->balance"); // 100
    $wallet = Wallet::query()->first()->cache();
    debug("当前钱包金额（查Cache）: $wallet->balance"); // 100
```

CacheModel对象和Model尽量保持一致性

### 事务

```injectablephp
    $wallet = Wallet::query()->first();
    $wallet->balance = 200;
    $wallet->save();
    
    $wallet1 = Wallet::query()->first()->cache(true); // 这里这样就必须得保存才有用了
    $wallet1->incrementByCache('balance', 100);   // 本来是200 这里是无效修改


    $wallet = Wallet::query()->first();
    info($wallet->balance);  // 200
    
    $wallet = Wallet::query()->first()->cache();
    info($wallet->balance);  // 200

    $wallet1 = Wallet::query()->first()->cache(true);
    $wallet1->incrementByCache('balance', 100); 
    $wallet1->saveCache();  // 从这里开始等于100 , 有保存的会生效
```
