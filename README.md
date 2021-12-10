# Laravel Model Cache

> 这是一个开发自己用的依赖库, 不过侵入性不高，所以开源

## 安装

```
composer require linty/laravel_model_cache
```

## 说明

本插件全部用的Cache， Cache使用的Redis存储 功能为，对某个模型的对象的某个字段进行代理，使用Cache维护 一段时间(暂时为10分钟)内的修改全部在Cache进行, 以此优化性能

## 使用

1. 队列新增一个queue，名称为：model-cache ，用于维护字段
2. 在需要使用的Model类嵌入

```
    use ModelCacheTrait;
```

3. 在原来的模型后面增加->cache()

```
    $wallet = Wallet::query()->first()->cache();
```

4. 开始正常使用吧

## 详细使用

### 修改数据，并使用Redis代理

```
    $wallet = Wallet::query()->first()->cache();
    $wallet->balance_cache = 100;   // 修改balance字段的值，并开始缓存
    
    // 修改 balance 或者 balance_cache 的效果等值，都是对缓存进行修改
    // 建议使用_cache后缀，用于分辨普通模型的修改，以免老眼昏花
    // 此时常规模型  Wallet::query()->first()->balance 也会获取到最新的缓存数据
    // 优先级： 缓存 > 数据库
```

### 代理修改了数据，普通模型也修改了数据

```
    $wallet1 = Wallet::query()->first()->cache();
    $wallet1->balance_cache = 88;  // 这里的修改，会在10分钟后才保存到数据库

    $wallet = Wallet::query()->first();
    info($wallet->balance);        // 但是这里能读到哦    88
    $wallet->balance = 200;
    $wallet->save();               // 这里保存新的值为200 ，放弃之前的修改，缓存就失效了

    info($wallet1->balance_cache);  // 200 ， 如果你在其他模型更新了，这里会硬生生同步
```

### 不想修改就生效怎么办

```
    $wallet1 = Wallet::query()->first()->cache(true); // 这里这样就必须得保存才有用了
    $wallet1->balance_cache = 88;   // 本来是200 这里是无效修改


    $wallet = Wallet::query()->first();
    info($wallet->balance);  // 200

    $wallet1 = Wallet::query()->first()->cache(true);
    $wallet1->balance_cache = 88;
    $wallet1->saveCache();

    $wallet = Wallet::query()->first();
    info($wallet->balance);  // 88
```
