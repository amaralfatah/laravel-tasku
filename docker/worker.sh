#!/bin/bash

# One background service covers both loops: the queue, and the scheduler
# defined in routes/console.php. If either one exits the container exits too,
# so the platform restarts it rather than leaving half the work running.

php artisan schedule:work &
scheduler=$!

php artisan queue:work --tries=3 --backoff=10 --max-time=3600 --sleep=3 &
queue=$!

# Signal the children by pid. Signalling the process group instead would
# re-enter this handler and take the shell down with a segfault.
terminate() {
    trap - TERM INT
    kill -TERM "$scheduler" "$queue" 2>/dev/null
    wait
    exit 0
}
trap terminate TERM INT

wait -n
status=$?
kill -TERM "$scheduler" "$queue" 2>/dev/null
wait
exit "$status"
