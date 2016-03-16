# Croney
PHP-based CRON scheduler

Don't you hate having to juggle a gazillion cronjobs for each application? We
sure do! We _also_ hate having to adhere to a library-specific code format to
circumvent this problem (e.g. Symfony, Laravel... you know who you are).

You know what we'd like to do? We just want to register a bunch of callables
and have a central script figure it out. Hello Croney!

## Installation

### Composer (recommended)
```sh
composer require monomelodies/croney
```

### Manual
1. Download or clone the repository;
2. Add the namespace `Croney` for the path `path/to/croney/src` to your PSR-4
   autoloader.

## Setting up the executable
Croney needs to run periodically, so create a simple executable that we will add
as a cronjob:

```php
#!/usr/bin/php
<?php

// Let's assume this is in bin/cron.
// It's empty for now.
```

```sh
$ chmod a+x bin/cron
$ crontab -e
```

How often you let Croney run is up to you. The default assumption is every
minute since it is the smallest interval possible on Unix-like systems. We'll
see how to optimise this to e.g. every five minutes later on. For now, register
the cronjob with `* * * * *`, i.e. every minute.

## The `Scheduler` class
At Croney's core is an instance of the `Scheduler` class. This is what is used
to run tasks and it takes care (as the name implies) of scheduling them.

In your `bin/cron` file:

```
#!/usr/bin/php
<?php

use Croney\Scheduler;

$schedule = new Scheduler;
```

## Adding tasks
The `Scheduler` extends `ArrayObject`, so to add a task simply set it. The value
should be a callable:

```
#!/usr/bin/php
<?php

// ...
$schedule['some-task'] = function () {
    // ...perform the task...
};
```

This task gets run every minute (or whatever interval you set your cronjob to).
A task can be any callable, including class methods (even static ones), but the
`$this` property is bound to the scheduler itself (for utility purposes as we'll
see shortly), so it's usually best to use an actual lambda.

> If your task is stored e.g. inside a class method, just call it from the
> lambda instead of passing it directly. The usage of `$this` would be ambiguous
> otherwise, which might lead to complications down the road.

When you've setup all your tasks, call `process` on the `Scheduler` to actually
run them:

```php
<?php

// ...
$schedule->process();
```

## Running tasks at specific intervals or times
To have more control over when exactly a task is run, you call the `at`
method on the bound `$this` object:

```
<?php

$scheduler['some-task'] = function () {
    $this->at('Y-m-d H:m');
};
```

The parameter to `at` is a PHP date string which, when parsed using the run's
start time, should `preg_match` it. The above example runs the task every minute
(which is the default assuming your cronjob runs every minute). To run a task
every five minutes instead, you'd write this:

```php
<?php

$scheduler['some-task'] = function () {
    // Note the double escape for \d in the regex.
    $this->at('Y-m-d H:\\\d[05]');
};
```

> Due to PHP's `strtotime` implementation, you can leave the `Y-m-d` part off
> for convenience if they're not relevant. The same goes for the hour/minute
> part in which case they'll default to "midnight".

Note that the seconds part can be left off as it defaults to `":00"`. Also note
that `at` breaks off the task if it's not due yet, so it should in almost all
cases be the first statement in a task.

> Any operations prior to `at` will always be executed. In rare cases this might
> be intentional, but normally it really won't be. Trust us.

## Running the script less often
We mentioned earlier how you can also choose to run the cronjob less often than
every minute, say every five minutes. If you only have tasks that run every five
minutes (or multiples of that), that's fine and no further configuration is
required. But what if you want to run your cronjob every five minutes, _but_
still be able to schedule tasks based on minutes?

> An example of this would be a cronjob that runs every five minutes, defining
> five tasks, each of which is run one minute after the previous task.

On the `Scheduler` object, call the `setDuration` method. This takes a single
integer parameter: the number of minutes the script is meant to run.

```php
<?php

$scheduler->setDuration(5); // Runs for five minutes
```

(As you'll have guessed, the default value here is `1`.)

When you call `process`, the tasks will actually be run 5 times (once every
minute) and executed when the time is there. E.g.:

```php
<?php

// ...

// First task, runs only on the first loop
$scheduler['first-task'] = function () {
    $this->at('H:00');
};
// Second task, runs only on the second loop
$scheduler['second-task'] = function () {
    $this->at('H:01');
};
// etc.
```

Croney calls PHP's `sleep` function in between loops.

> Croney tries to calculate the _actual_ number of seconds to sleep, so if the
> tasks from the first loop took, say, 3 seconds in total it sleeps for 57
> seconds before the next loop. Note however that this is _not_ exact and does
> _not_ guarantee that your task will run _exactly_ on the dot. If your task
> involves time-based operations make sure to "round down" the time to the
> expected value.

In theory, you could let your script run at midnight on January the first and
calculate everything from there. In the real world, this is obviously not
practical since any error whatsoever means you have to wait a whole year to see
if your fix solved the problem!

Typical values are every 5 or 10 minutes, maybe 30 or 60 on very busy servers.

## Long running tasks
Typically a task runs in (micro)seconds, but sometimes one of your tasks will be
"long running". If this is intentional (e.g. a periodic cleanup operation of
user-uploaded files) you would obviously `runAt` it at a safe interval, and you
should take care limit stuff in your task itself (e.g. "max 100 files per run").
Still, every so often you'll need to write a task that should run often, but
_might_ in extreme cases take longer than expected to do so.

A fictional example: a task that reads a mailbox (e.g. to push them into a
ticketing system). If that mailbox explodes for whatever reason (let's be
positive and imagine your application became _really_ popular overnight ;)) this
would pose a problem: the previous run might still be reading mails as the next
run starts, causing mails to be handled twice. Obviously not desirable.

Croney "locks" each task prior to running, and does not attempt to re-run as
long as it is locked. If a run fails due to locking, a warning is logged and the
task is retried periodically for as long as the cronjob runs. If the task
couldn't be run before the cronjob ends, an error is logged.

The locking is done based on an MD5 hash of the reflected callable, so any
changes between runs will invalidate any existing locks.

## Error handling
You can pass an instance of `Monolog\Logger` as an argument to the `Scheduler`
constructor. This will then be used to log any messages triggered by tasks, in
the way that you specified.

If no logger was defined, all messages go to `STDERR`.

