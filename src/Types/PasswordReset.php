<?php

namespace markhuot\CraftQL\Types;

use Craft;
use markhuot\CraftQL\Builders\Schema;

class PasswordReset extends Schema {
    function boot() {
        $this->addBooleanField('success')->nonNull();
    }

}
