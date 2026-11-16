<?php

arch()->expect('App\Domain')->not->toUse('Illuminate');

arch()->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])->not->toBeUsed();

arch()->expect('App\Enums')->toBeEnums();
