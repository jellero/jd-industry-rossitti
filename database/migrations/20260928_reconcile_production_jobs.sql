USE commesse_lite;

-- Rimuove associazioni di produzione incompatibili con la commessa:
-- macchina diversa oppure nome commessa macchina diverso dal job_code.
UPDATE production_records pr
JOIN jobs j ON j.id = pr.job_id
SET pr.job_id = NULL
WHERE
  (j.machine_id IS NOT NULL AND j.machine_id <> pr.machine_id)
  OR pr.remote_order_name IS NULL
  OR TRIM(pr.remote_order_name) = ''
  OR TRIM(pr.remote_order_name) = '-'
  OR TRIM(pr.remote_order_name) <> j.job_code;

-- Riassocia solo i record per i quali codice commessa e macchina coincidono.
UPDATE production_records pr
JOIN jobs j
  ON j.job_code = TRIM(pr.remote_order_name)
 AND (j.machine_id = pr.machine_id OR j.machine_id IS NULL)
SET pr.job_id = j.id
WHERE pr.job_id IS NULL
  AND pr.remote_order_name IS NOT NULL
  AND TRIM(pr.remote_order_name) <> ''
  AND TRIM(pr.remote_order_name) <> '-';
