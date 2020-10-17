<?php

namespace akiyatkin\meta;

use infrajs\ans\Ans;
use infrajs\path\Path;
use infrajs\db\Db;
use infrajs\mail\Mail;
use infrajs\access\Access;
use infrajs\lang\Lang;

//lang может быть использован из адреса.
//lang meta и lang приложения не могут отсутствовать/не совпадать
class Meta {
	public $params = [];
	public $list = [];
	public function __construct($name = 'meta', $lang = 'ru', $src = false) {
		$this->ans = [];
		$this->lang = $lang;
		$this->name = $name;
		$this->src = $src;
	}
	public function &add($pname, $a1, $a2, $a3 = null) {
		$from = null;
		$after = null;
		$before = null;
		$func = null;
		if (is_callable($a1)) {			
			$func = $a1;
			$after = $a2;
		} else if (is_callable($a2)) {
			$func = $a2;
			$before = $a1;
			$after = $a3;
		} else if (is_string($a1)) {
			$from = $a1;
			$after = $a2;
		} else if (is_string($a2)) {
			$from = $a2;			
			$before = $a1;
			$after = $a3;
		} else {
			$before = $a1;
			$after = $a2;
		}
		$this->list[$pname] = [
			'request' => false, //Нужно ли брать из REQUEST
			'required' => false, //Нужно ли выкидывать исключение если нет request
			'result' => null,
			'ready' => false,
			'cache' => false,
			'func' => $func,
			'after' => $after,
			'before' => $before,
			'from' => $from
		];
		return $this->list[$pname];
	}
	public function addHandler() {
		$opt = &$this->add($pname, $a1, $a2, $a3);
		$opt['cache'] = true;
		$opt['request'] = false;
		$opt['required'] = false;
	}
	public function addArguments() {
		$opt = &$this->add($pname, $a1, $a2, $a3);
		$opt['cache'] = true;
		$opt['request'] = true;
		$opt['required'] = true;
	}
	public function addVariable() {
		$opt = &$this->add($pname, $a1, $a2, $a3);
		$opt['cache'] = true;
		$opt['request'] = false;
		$opt['required'] = false;
	}
	public function addActions() {
		$opt = &$this->add($pname, $a1, $a2, $a3);
		$opt['cache'] = true;
		$opt['request'] = false;
		$opt['required'] = false;
	}
	public function addFunctions() {
		$opt = &$this->add($pname, $a1, $a2, $a3);
		$opt['cache'] = false;
		$opt['request'] = false;
		$opt['required'] = false;
	}
	public function is($pname) {
		return $this->list[$pname]['ready'];
	}
	public function init($action) {
		try {
			return $this->initnow($action);//Exception нужны чтобы различать ответ от ошибки
		} catch (\Exception $e) {			
			return $this->ans;
		}

	}
	public function initnow($action) {
		$this->action = $action;
		$this->get($action);
		$this->ret('ready');
	}
	public function &get($action, $parentvalue = null, $parentname = null) {
		if (empty($this->list[$action])) $this->fail('notfound');
		$opt = &$this->list[$action];
		if ($opt['cache'] && $opt['ready']) return $opt['result'];
		$res = null;
		if ($opt['before']) {
			foreach ($opt['before'] as $n) {
				$this->get($n, $res, $action);
			}
		}
		if ($opt['from']) {
			$res = $this->get($opt['from'], $res, $action);
		} else if ($opt['func']) {
			if ($opt['request']) {
				$res = Ans::REQS($action)
			}
			if ($opt['required']) {
				if (is_null($res)) $this->fail('required', $action);
			}
			\Closure::bind($opt['func'], $this)($res, $action, $parentvalue, $parentname);
			$fn();
		}
		if ($opt['after']) {
			foreach ($opt['after'] as $n) {
				$this->get($n, $res, $action);
			}	
		}
		$opt['result'] = &$res;
		return $opt['result'];
	}


	public function addBacktraceLines($count = 8) {
		$back = debug_backtrace();
		array_splice($back, sizeof($back) - 5);
		foreach ($back as $i => $e) {
			unset($back[$i]['object']);
			$name = basename($e['file'] ?? '');
			if ($name == 'Meta.php') unset($back[$i]);
			if (empty($back[$i]['class'])) continue;
		}
		unset($back[0]);
		$lines = [];
		$c = 0;
		foreach ($back as $i => $e) {
			if (empty($e['file'])) continue;
			if (++$c > $count) break;

			$lines[] = $e['line'];
		}
		return implode('-', $lines);
	}
	public function addBacktrace() {
		$ans = &$this->params['ans'];
		$back = debug_backtrace();
		foreach ($back as $i => $e) {
			unset($back[$i]['object']);
		}
		unset($back[0]);
		$lines = [];
		foreach ($back as $i => $e) {
			if (empty($e['file'])) continue;
			$lines[] = basename($e['file']).', '.$e['line'].', '.$e['function'];
		}
		$ans['backtrace'] = $lines;
	}

	/*
		Языковой файл для кода может быть как в meta/ так и в том расширении где используется meta/
	*/
	public function _fail($namecode, $pname = false) {
		if ($this->question) throw new \Exception();
		$ans = &$this->ans;
		$lang = $this->list['lang']['result'] ?? $this->lang;
		if (Access::isDebug()) $this->addBacktrace();

		$ans['params'] = array_keys(array_filter($this->list, function ($opt) {
			return $opt['ready'];
		}));
		
		if (!$pname) {			
			$ans = Lang::fail($ans, $lang, $namecode.'.'.$this->action.'-'.$this->addBacktraceLines());
			throw new \Exception();
		}
		$ans['payload'] = $pname;
		$ans = Lang::failtpl($ans, $lang, $namecode);
		throw new \Exception();
	}
	public function fail($code, $pname = false) {
		return $this->_fail($this->name.'.'.$code);
	}
	public function _err($namecode, $pname = false) {
		if ($this->question) throw new \Exception();
		$ans = &$this->ans;
		$lang = $this->list['lang']['result'] ?? $this->lang;
		if (Access::isDebug()) $this->addBacktrace();
		$ans['params'] = array_keys(array_filter($this->list, function ($opt) {
			return $opt['ready'];
		}));

		if (!$pname) {
			$ans = Lang::err($ans, $lang, $namecode);
			throw new \Exception();
		} 
		$ans['payload'] = $pname;
		$ans = Lang::errtpl($ans, $lang, $namecode);
		throw new \Exception();
	}
	public function err($code, $pname = false) {
		return $this->_err($this->name.'.'.$code);
	}
	public function _ret($namecode = false, $pname = false) {
		$ans = &$this->ans;
		$lang = $this->list['lang']['result'] ?? $this->lang;
		if (Access::isDebug()) $this->addBacktrace();
		if (!$pname) return Lang::ret($ans, $lang, $this->name.'.'.$code);
		$ans['payload'] = $pname;
		return Lang::rettpl($ans, $lang, $this->name.'.'.$code);
	}
	public function ret($code, $pname = false) {
		if (!$code) return Lang::ret($ans);
		return $this->_ret($this->name.'.'.$code);
	}

}