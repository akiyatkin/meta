<?php

use akiyatkin\meta\Meta;



$context = new Meta('vendor/akiyatkin/meta/meta.json'); //Если указан путь, то всё светяерся с meta.json

//handler, приходит какое-то стартовое значение, смысл которого зависит от того к чему handler привязан (arg, action, handler, var)
//A запускает Б, а Б запускает handler С, которому нужен и A и Б -- амперсанд не исопльзуется
//handler запускаеться может перед какой-то обработкой или после. Всё может работать без meta.json

//Обработка не зависит от родителя.
$context->addAction('post', function () {
	$submit = ($_SERVER['REQUEST_METHOD'] === 'POST' || Ans::GET('submit', 'bool'));
	if (!$submit) return $this->fail('lang.post');
})

//Обработка с зависимостью от родителя
$context->addHandler('notempty', ['post'], function ($notempty, $pname) {
	if (!$notempty) return $this->fail('empty', $pname)
},['check'])

$context->addHandler('Check the legality of the action', function ($notempty, $pname) {
	if (!$notempty) return $this->fail('empty', $pname)
},['check'])

//Обработка не зависиот от родителя. Приходит Request, наличие обязательно, из адреса не запускается
$context->addArgument('order_id', function ($order_id) {

})

$context->addAction('order_id@valid', function () {
	extract($this->gets(['order_id']));
	if (strlen($order_id)<10) return $this->fail('notvalid', $pname)
},['Check the legality of the action'])

$context->addHandler('notempty', function ($beforename) {
	extract($this->gets(['order_id']));
	if (strlen($order_id)<10) return $this->fail('notvalid', $pname)
})