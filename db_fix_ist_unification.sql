SET time_zone = '+05:30';

-- Normalize historical submission rows where server-side submitted_at
-- was stored roughly 5 hours 30 minutes behind the device-submitted IST.
UPDATE submissions
SET submitted_at = submitted_at + INTERVAL 330 MINUTE
WHERE device_submitted_at IS NOT NULL
  AND submitted_at IS NOT NULL
  AND TIMESTAMPDIFF(MINUTE, submitted_at, device_submitted_at) BETWEEN 300 AND 360;

-- Optional verification query.
SELECT
    COUNT(*) AS normalized_rows
FROM submissions
WHERE device_submitted_at IS NOT NULL
  AND submitted_at IS NOT NULL
  AND TIMESTAMPDIFF(MINUTE, submitted_at, device_submitted_at) BETWEEN 300 AND 360;
