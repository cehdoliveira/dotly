<?php
class product_images_model extends DOLModel
{
    protected array $field = [" idx ", " products_id ", " path ", " is_cover ", " sort_order "];
    protected array $filter = [" active = 'yes' "];

    function __construct()
    {
        parent::__construct("product_images");
    }
}
