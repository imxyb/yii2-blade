# yii2-blade
yii2集成laravel的blade模板引擎

> 并没继承Laravel中的模板继承、视图片断功能。

# 安装
composer.json 添加一行

```
"xyb/yii2-blade": "*"
```

执行composer update就ok了。

#使用
    'view' => [
    	'class' => 'yii\web\View',
    	'renderers' => [
    	   'blade' => [
    	   'class' => 'xyb\blade\ViewRenderer',
    	   //'cachePath' => '@runtime/blade',
    	   ],
    	],
    ],

默认会把layout的.php换成.blade,也就是说layout => main,会加载layouts/main.blade而不是layouts/main.php，同时渲染模板时候这样调用 ` $this->render('tpl.blade')`
