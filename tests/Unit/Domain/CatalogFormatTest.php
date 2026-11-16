<?php

use App\Domain\Catalog\CatalogFormat;

it('strips exactly one apostrophe before an apostrophe or spreadsheet formula prefix', function () {
    expect(CatalogFormat::importCell("'=SUM(A1)"))->toBe('=SUM(A1)');
    expect(CatalogFormat::importCell("''=SUM(A1)"))->toBe("'=SUM(A1)");
    expect(CatalogFormat::importCell("'ordinary"))->toBe("'ordinary");
    expect(CatalogFormat::importCell("'\tvalue"))->toBe("\tvalue");
    expect(CatalogFormat::exportCell('ordinary'))->toBe('ordinary');
    expect(CatalogFormat::exportCell(123))->toBe(123);
});

it('reversibly escapes formula and apostrophe prefixes', function (string $original, string $exported) {
    expect(CatalogFormat::exportCell($original))->toBe($exported);
    expect(CatalogFormat::importCell($exported))->toBe($original);
})->with([
    ['=x', "'=x"],
    ["'=x", "''=x"],
    ["''=x", "'''=x"],
    ["'quoted'", "'quoted'"],
    ["''quoted'", "'''quoted'"],
    ["'+x", "''+x"],
    ["'-x", "''-x"],
    ["'@x", "''@x"],
    ["'\tx", "''\tx"],
    ["'\rx", "''\rx"],
]);
