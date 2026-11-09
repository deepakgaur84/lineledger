<?php

use App\Enums\Section;

it('gates the Bulk Import tool with the Settings section', function () {
    expect(Section::forRouteName('tools.bulk-import'))->toBe([Section::Settings]);
});
