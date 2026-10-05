<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up(): void { DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION mue_capacity() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE o offers%ROWTYPE; s company_sites%ROWTYPE; n integer;
BEGIN
 IF NEW.state IN ('onaylandi','ilan_edildi','baslamaya_hazir','basladi','askida') THEN
  SELECT * INTO o FROM offers WHERE id=NEW.offer_id;
  SELECT * INTO s FROM company_sites WHERE id=o.site_id FOR UPDATE;
  SELECT * INTO o FROM offers WHERE id=NEW.offer_id FOR UPDATE;
  PERFORM 1 FROM trainer_qualifications WHERE id=o.trainer_id FOR UPDATE;
  SELECT count(*) INTO n FROM placements WHERE offer_id=NEW.offer_id AND id<>NEW.id AND state IN ('onaylandi','ilan_edildi','baslamaya_hazir','basladi','askida');
  IF n>=o.capacity THEN RAISE EXCEPTION 'Kontenjan dolu'; END IF;
  IF s.capacity_type='ortak' THEN
   SELECT count(*) INTO n FROM placements p JOIN offers x ON x.id=p.offer_id WHERE x.site_id=s.id AND p.term_id=NEW.term_id AND p.id<>NEW.id AND p.state IN ('onaylandi','ilan_edildi','baslamaya_hazir','basladi','askida');
   IF s.total_capacity IS NULL OR n>=s.total_capacity THEN RAISE EXCEPTION 'Şubenin ortak kontenjanı dolu'; END IF;
  END IF;
  SELECT count(*) INTO n FROM placements p JOIN offers x ON x.id=p.offer_id WHERE x.trainer_id=o.trainer_id AND p.term_id=NEW.term_id AND p.id<>NEW.id AND p.state IN ('onaylandi','ilan_edildi','baslamaya_hazir','basladi','askida');
  IF n>=5 THEN RAISE EXCEPTION 'Eğitici başına beş öğrenci sınırı dolu'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE FUNCTION mue_site_reduction() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE n integer;
BEGIN
 IF NEW.capacity_type='ortak' THEN
  SELECT max(used) INTO n FROM (SELECT count(*) used FROM placements p JOIN offers o ON o.id=p.offer_id WHERE o.site_id=NEW.id AND p.state IN ('onaylandi','ilan_edildi','baslamaya_hazir','basladi','askida') GROUP BY p.term_id) q;
  IF NEW.total_capacity IS NULL OR NEW.total_capacity<coalesce(n,0) THEN RAISE EXCEPTION 'Şube kapasitesi yerleştirmelerin altına düşürülemez'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER site_reduction BEFORE UPDATE ON company_sites FOR EACH ROW EXECUTE FUNCTION mue_site_reduction();
SQL); }
 public function down(): void { throw new RuntimeException('Yedekten geri dönüş uygulayın.'); }
};
