<?php
class products_model extends DOLModel
{
    protected array $field = [" idx ", " name ", " slug ", " category ", " is_infinity ", " description ", " dosage ", " purity_label ", " price_unit_cents ", " box_qty ", " stock "];
    protected array $filter = [" active = 'yes' "];

    function __construct()
    {
        parent::__construct("products");
    }
}
