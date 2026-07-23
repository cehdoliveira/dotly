<?php
class order_items_model extends DOLModel
{
    protected array $field = [" idx ", " orders_id ", " products_id ", " product_name ", " variant ", " qty ", " unit_price_cents ", " line_total_cents "];
    protected array $filter = [" active = 'yes' "];

    function __construct()
    {
        parent::__construct("order_items");
    }
}
