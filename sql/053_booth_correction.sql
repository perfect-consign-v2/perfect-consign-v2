-- A wrong booth moves vendor payable. It does not touch the piece.
-- Apply after 052. The provisioner rewrites stall_poster.

INSERT INTO chart_source_rules (role, source_type)
SELECT 'vendor_payable', 'booth_correction'
WHERE NOT EXISTS (
    SELECT 1
    FROM chart_source_rules
    WHERE role = 'vendor_payable'
      AND source_type = 'booth_correction'
);
