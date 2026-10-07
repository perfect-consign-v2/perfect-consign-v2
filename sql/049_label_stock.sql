-- Which stickers this mall prints. A print job can still pick another stock.
-- Apply inside each tenant schema, after 048. The provisioner rewrites stall_poster.

ALTER TABLE mall ADD COLUMN label_stock text NOT NULL DEFAULT 'avery_8160';

ALTER TABLE mall ADD CONSTRAINT mall_label_stock_chk CHECK (
    label_stock IN (
        'avery_8160',
        'avery_94073',
        'bcw_consignment',
        'bcw_consignment_2up',
        'bcw_jewelry'
    )
);
