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
Croney needs to run periodically, so create a simple executable the we will add
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

$scheduler = new Scheduler;
```

## Adding tasks
To add a task to the `Scheduler`, call its `task` method and supply a callable:

```
#!/usr/bin/php
<?php

// ...
$scheduler->task(function () {
    // ...perform the task...
});
```

This task gets run every minute (or whatever interval you set your cronjob to).
A task can be any callable, but the `$this` property is bound to the scheduler
itself (for utility purposes as we'll see shortly), so it's best to use an
actual lambda.

> If your task is stored e.g. inside a class method, just call it from the
> lambda instead of passing it directly. The usage of `$this` would be ambiguous
> otherwise, which might lead to complications down the road.

When you've setup all your tasks, call `process` on the `Scheduler` to actually
run them:

```php
<?php

// ...
$scheduler->process();
```

## Running tasks at specific intervals or times
To have more control over when exactly a task is run, you call the `runAt`
method on the bound `$this` object:

```
<?php

$scheduler->task(function () {
    $this->runAt('Y-m-d H:m');
});
```

The parameter to `runAt` is a PHP date string which, when parsed using the run's
start time, should `preg_match` it. The above example runs the task every minute
(which is the default assuming your cronjob runs every minute). To run a task
every five minutes instead, you'd write this:

```php
<?php

$scheduler->task(function () {
    $this->runAt('Y-m-d H:\d[05]');
});
```

Note that the seconds part can be left off as it defaults to `":00"`. Also note
that `runAt` breaks off the task if it's not due yet, so it should in almost all
cases be the first statement in a task!

> Any operations prior to `runAt` will always be executed. In rare cases this
> might be intentional.

## Running the script less often
We mentioned earlier how you can also choose to run the cronjob less often than
every minute, say every five minutes. If you only have tasks that run every five
minutes (or multiples of that), that's fine and no further configuration is
required. But what if you want to run your cronjob every five minutes, _but_
still be able to schedule tasks based on minutes?

> An example of this would be a cronjob that runs every five minutes, defining
> five tasks, each of which is run one minute apart.

On the `Scheduler` object, call the `setDuration` method. This takes a single
integer parameter: the number of minutes the script is meant to run.

```php
<?php

$scheduler->setDuration(5); // Runs for five minutes
```

(As you'll have guessed, the default value here is `1`.)

## Long running tasks


## 
## Setup and usage

### Tracking status
Croney assumes your application has a database to log execution in. It also
assumes said database is `PDO` compatible. In the `info/sql` folder you'll find
table declarations, so make sure they exist in your RMDBS instance. If your
vendor isn't supported out of the box, you'll have to write your own - otherwise
just copy/paste. Just make sure the table and column names stay the same.

We recommend just using an SQLite database for this, but you can also use your
main database if you prefer.

### Executing from the CLI
You're going to need to schedule an executable script in your crontab, and we
can't do that for you. Let's say we call it `bin/cron`.

In essence, the script will look as follows:

```php
#!/usr/bin/php
<?php

use Croney\Scheduler;

(new Scheduler(new PDO($your_dsn_and_other_stuff))
    ->task('a-unique-task-id', null, function () {
        // do stuff related to this task
    }))
    // ... more tasks... 
    ->process();
```

In Unix-like systems, run `crontab -e` and add `* * * * * /path/to/bin/cron` to
let it run every minute.

> The exact procedure or syntax here is system-dependent, but just let it run as
> often as possible.

On busy servers you can also specify less frequent intervals; Croney will still
run all due jobs whenever it gets its turn.

> Don't forget to mark the script as executable, e.g. `chmod a+x /your/script`
> on Unix-like systems.

## Defining and scheduling tasks
Each task in Croney is simply a callable which is added to the `Scheduler` via
its `task` method. On an initial run, all these tasks are executed (much like a
Gulpfile). Hence the default is to run every defined task once each minute (or
whatever you set your cronjob to - we're going to assume "each minute" for the
rest of this readme).

Of course Croney wouldn't be much use if it just executed all tasks every
minute. The _second_ argument to `task` can be a string that is parsable by
[PHP's strtotime](http://php.net/strtotime) specifying the next moment the job
should be run.

So if we would want our `"a-unique-task-id"` task to run every half hour we
could call it like so:

```php
// ...
$schedule->task('a-unique-task-id', '+30 minutes', function () {
    // Task operations...
});
```

Croney runs (at the most) every minute, so if you define a shorter interval it
will be "rounded" to the next minute (or whatever you set your cronjob to).
Croney simply executes all due jobs in the past, and since job names are unique
there can only ever be one job of a type in any given cycle. So if you configure
your cron script to run, say, every day at midnight, all tasks will only ever be
executed once a day at the most.

## Running at specific times
The previous example showed how to run a task every half hour, but what if you
need to run a task _on_ every half hour, exactly? After all, if we add the task
at, say, 11:34AM it will run immediately, and be rescheduled for 12:34AM.

> Note that "exactly" depends on how many tasks you have and how long they take
> to run; there might be a few seconds - or in extreme cases even minutes -
> delay before the task starts.

Simply manually insert the task ID into the `croney_job` table, and specify a
`datedue` in the future at the desired exact time. In the above case that would
be `Y-m-d 12:00:00`. The task will now run for the first time in 26 minutes, and
after that on every half hour since the due date gets increment by
`+30 minutes`.

After this is set up, add the task to the scheduler.

## Error handling

