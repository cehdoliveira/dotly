<?php
class payment_gateways_model extends DOLModel
{
    protected array $field = [" idx ", " name ", " slug ", " mode ", " enabled ", " monthly_limit_cents ", " max_order_cents ", " avoid_on_spike "];
    protected array $filter = [" active = 'yes' "];

    function __construct()
    {
        parent::__construct("payment_gateways");
    }
}
