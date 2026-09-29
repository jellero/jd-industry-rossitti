USE commesse_lite;

-- Rafforza le associazioni tra produzione Maestro e commesse:
-- solo commesse MAESTRO_REST, stesso codice e macchina compatibile.

UPDATE production_records pr
JOIN jobs j ON j.id = pr.job_id
JOIN job_types jt ON jt.id = j.job_type_id
SET pr.job_id = NULL
WHERE
  jt.source_type <> 'MAESTRO_REST'
  OR (j.machine_id IS NOT NULL AND j.machine_id <> pr.machine_id)
  OR pr.remote_order_name IS NULL
  OR TRIM(pr.remote_order_name) = ''
  OR TRIM(pr.remote_order_name) = '-'
  OR TRIM(pr.remote_order_name) <> j.job_code;

UPDATE production_records pr
JOIN jobs j
  ON j.job_code = TRIM(pr.remote_order_name)
 AND (j.machine_id = pr.machine_id OR j.machine_id IS NULL)
JOIN job_types jt
  ON jt.id = j.job_type_id
 AND jt.source_type = 'MAESTRO_REST'
SET pr.job_id = j.id
WHERE pr.job_id IS NULL
  AND pr.remote_order_name IS NOT NULL
  AND TRIM(pr.remote_order_name) <> ''
  AND TRIM(pr.remote_order_name) <> '-';
