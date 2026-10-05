<?php

/*
 * swerve.psr15.php: what the entry function makes of it.
 */

/** Start swerve on an application directory that is wrong: its exit code and log. */
function psr15_fail(string $dir): array
{
    [$process, , $log] = psr15_spawn($dir, ['--workers=1']);
    [$code]            = swerve_wait($process, 10);

    return [$code, \file_get_contents($log)];
}

test('without a swerve.psr15.php swerve stops with exit code 2 and says what is missing', function () {
    [$code, $log] = psr15_fail(psr15_app(null));

    expect($code)->toBe(2);
    expect($log)->toContain('swerve.psr15.php not found');
});

test('a swerve.psr15.php that does not return a PSR-15 handler stops swerve with exit code 2', function (string $code, string $returned) {
    [$exit, $log] = psr15_fail(psr15_app(null, $code));

    expect($exit)->toBe(2);
    expect($log)->toContain("returned $returned; it must return a Psr\\Http\\Server\\RequestHandlerInterface");
})->with([
    'nothing'     => ['', 'int'],
    'an array'    => ['return [];', 'array'],
    'a closure'   => ['return fn () => null;', 'Closure'],
    'some object' => ['return new stdClass();', 'stdClass'],
]);

test('an application that throws while it is built stops swerve with exit code 2', function () {
    [$code, $log] = psr15_fail(psr15_app(null, 'throw new RuntimeException("no database");'));

    expect($code)->toBe(2);
    expect($log)->toContain('no database');
});

test('a swerve.psr15.php that returns a handler is served', function () {
    [$process, $addr] = psr15_spawn(psr15_app('plain.php'), ['--workers=1']);
    $deadline         = \microtime(true) + 10;
    while (!psr15_answers($addr)) {
        expect(\microtime(true))->toBeLessThan($deadline);
        \usleep(50000);
    }

    expect(psr15_get($addr, '/')['body'])->toBe('ok');
    native_stop($process);
});
