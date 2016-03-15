<?php

namespace Croney;

use PDO;
use StdClass;

class Scheduler
{
    private $pdo;
    private $stmt;
    private $allJobs;
    private $getJobs;
    private $reset;
    private $start;
    private $now;
    private $jobs = [];

    public function __construct(PDO $pdo)
    {
        $this->now = strtotime(date('Y-m-d H:i:00'));
        $this->pdo = $pdo;
        $this->stmt = new StdClass;
        $this->stmt->check = $this->pdo->prepare(
            "SELECT id FROM croney_job"
        );
        $this->stmt->add = $this->pdo->prepare(
            "INSERT INTO croney_job (id, running, datedue)
                VALUES (?, 0, ?)");
        $this->stmt->due = $this->pdo->prepare(sprintf(
            "SELECT * FROM croney_job WHERE
                datedue <= %s
                AND running = '0'",
            $this->pdo->quote(date('Y-m-d H:i:00', $this->now))
        ));
        $this->stmt->reset = $this->pdo->prepare(
            "UPDATE croney_job SET running = '0',
                datedue = ? WHERE id = ?");
        $this->stmt->start = $this->pdo->prepare(
            "UPDATE croney_job SET running = '1' WHERE id = ?");
    }

    /**
     * Add a named job to the schedule.
     *
     * @param string $id The unique name (id) of the job.
     * @param string $when String describing when the task should run.
     *  Can be either in a format `strtotime` understands, or a regular
     *  expression the current date/time must match.
     * @param callable $job The job.
     * @return self The scheduler, for chaining.
     */
    public function task($id, $when, callable $job)
    {
        if (!isset($when)) {
            $when = '+1 minute';
        }
        $this->jobs[$id] = [$when, $job];

        return $this;
    }

    /**
     * Process the schedule and run all jobs which are due.
     */
    public function process()
    {
        // First, clean up stale jobs and insert new ones.
        $this->stmt->check->execute();
        $all = [];
        while (false !== ($id = $this->stmt->check->fetchColumn())) {
            $all[] = $id;
        }
        $ids = array_keys($this->jobs);
        array_walk($ids, function (&$id) use ($all) {
            if (!in_array($id, $all)) {
                $this->stmt->add->execute([
                    $id,
                    $this->toDateTime($this->jobs[$id][0]),
                ]);
            }
            $id = $this->pdo->quote($id);
        });
        $this->pdo->exec(sprintf(
            "DELETE FROM croney_job WHERE id NOT IN (%s)",
            implode(', ', $ids)
        ));

        $this->stmt->due->execute();
        $jobs = $this->stmt->due->fetchAll(PDO::FETCH_ASSOC);
        $due = [];
        array_walk($jobs, function ($job) use (&$due) {
            $this->stmt->start->execute([$job['id']]);
            $due[$job['id']] = $this->jobs[$job['id']];
        });
        array_walk($due, function ($job, $id) {
            $this->stmt->start->execute([$id]);
            call_user_func($job[1]);
            $this->stmt->reset->execute([
                $this->toDateTime($job[0]),
                $id
            ]);
        });
    }

    protected function toDateTime($when)
    {
        $when = preg_replace_callback(
            "@[dDjlNSwzWFmMntLoYyaABgGhHisueIOPTZcrU]@",
            function ($match) {
                return '\\'.$match[0];
            },
            $when
        );
        $when = date($when);
        $when = strtotime($when);
        return date('Y-m-d H:i:00', $when);
    }
}

