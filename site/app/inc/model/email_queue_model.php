<?php
class email_queue_model extends DOLModel
{
    protected array $field = [" idx ", " event_type ", " orders_id ", " to_mail ",
        " subject ", " body ", " status ", " attempts ", " max_attempts ",
        " last_error ", " sent_at "];
    protected array $filter = [" active = 'yes' "];

    function __construct()
    {
        parent::__construct("email_queue");
    }
}
