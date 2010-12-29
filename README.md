model.php
=========

model.php is a super simple class to wrap up redis object model with php.

use this class is also simple, just checkout the post.php/test.php example.

since redis is not relational-based database, model.php only supports very simple lookups, such as look up certain properties. you shouldn't rely model.php to perform any sophisticate lookup operations, but for my specific usage (a social game backend), it is enough.
