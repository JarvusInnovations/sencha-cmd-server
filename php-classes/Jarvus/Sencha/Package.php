<?php

namespace Jarvus\Sencha;

class Package
{
	protected $name;

	public function __construct($path)
	{
		// TODO: load name from package.json
		$this->name = 'package-name';
	}

	public function getName()
	{
		return $this->name;
	}
}