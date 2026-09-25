-- A weekly schedule can run on several days. day_of_week becomes a comma
-- list, 0=Sunday (PHP's `w`), e.g. "1,3,5" (#492).
ALTER TABLE schedules MODIFY day_of_week VARCHAR(20) DEFAULT NULL;
