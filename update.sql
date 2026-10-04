-- SandLicense 0.1.0 is a fresh product. There is no legacy database upgrade path.
-- Idempotent current-version menu repair; never replay business table installation.
-- SAND_LICENSE_MENU_BEGIN
DO $sand_license_menu$
DECLARE
 root_id bigint;
 page_id bigint;
 permission_id bigint;
 row_count integer;
 permission record;
BEGIN
 SELECT count(*), min(id) INTO row_count, root_id FROM sand_system_menu WHERE code = 'SandLicense';
 IF row_count > 1 THEN RAISE EXCEPTION 'SandLicense menu root is duplicated'; END IF;
 IF row_count = 0 THEN
  INSERT INTO sand_system_menu (parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
  VALUES (0,'商业授权','SandLicense','',1,'/plugin/sand-license','','ri:key-2-line',95,2,1,CURRENT_TIMESTAMP AT TIME ZONE 'UTC',CURRENT_TIMESTAMP AT TIME ZONE 'UTC')
  RETURNING id INTO root_id;
 ELSIF NOT EXISTS (SELECT 1 FROM sand_system_menu WHERE id=root_id AND parent_id=0 AND type=1 AND path='/plugin/sand-license' AND component='' AND slug='' AND delete_time IS NULL) THEN
  RAISE EXCEPTION 'SandLicense root menu ownership conflicts';
 END IF;
 SELECT count(*), min(id) INTO row_count, page_id FROM sand_system_menu WHERE code = 'SandLicenseCenter';
 IF row_count > 1 THEN RAISE EXCEPTION 'SandLicense center menu is duplicated'; END IF;
 IF row_count = 0 THEN
  INSERT INTO sand_system_menu (parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
  VALUES (root_id,'商业许可','SandLicenseCenter','',2,'index','/plugin/sand-license/index/index','ri:key-2-line',100,2,1,CURRENT_TIMESTAMP AT TIME ZONE 'UTC',CURRENT_TIMESTAMP AT TIME ZONE 'UTC')
  RETURNING id INTO page_id;
 ELSIF NOT EXISTS (SELECT 1 FROM sand_system_menu WHERE id=page_id AND parent_id=root_id AND type=2 AND path='index' AND component='/plugin/sand-license/index/index' AND slug='' AND delete_time IS NULL) THEN
  RAISE EXCEPTION 'SandLicense center menu ownership conflicts';
 END IF;
 FOR permission IN SELECT * FROM (VALUES
  ('sand_license:product:index','软件产品列表'),
  ('sand_license:product:read','软件产品详情'),
  ('sand_license:product:save','保存软件产品'),
  ('sand_license:product:publish','发布软件产品'),
  ('sand_license:plan:index','套餐列表'),
  ('sand_license:plan:read','套餐详情'),
  ('sand_license:plan:save','保存套餐'),
  ('sand_license:plan:publish','发布套餐'),
  ('sand_license:code:index','卡密列表'),
  ('sand_license:code:read','卡密详情'),
  ('sand_license:code:issue','手工发码与结果查询'),
  ('sand_license:code:reissue','重新签发卡密'),
  ('sand_license:code:revoke','撤销卡密'),
  ('sand_license:entitlement:index','权益列表'),
  ('sand_license:entitlement:read','权益详情'),
  ('sand_license:entitlement:suspend','暂停权益'),
  ('sand_license:entitlement:revoke','撤销权益'),
  ('sand_license:entitlement:ticket','签发新设备资格'),
  ('sand_license:activation:index','设备激活列表'),
  ('sand_license:activation:read','设备激活详情'),
  ('sand_license:activation:release','释放设备'),
  ('sand_license:activation:reset','重置设备'),
  ('sand_license:sku_mapping:index','商品映射列表'),
  ('sand_license:sku_mapping:read','商品映射详情'),
  ('sand_license:sku_mapping:save','保存商品映射'),
  ('sand_license:fulfillment:index','履约列表'),
  ('sand_license:fulfillment:read','履约详情'),
  ('sand_license:membership:index','会员权益列表'),
  ('sand_license:membership:read','会员权益详情'),
  ('sand_license:event:index','操作记录列表'),
  ('sand_license:event:read','操作记录详情')
 ) AS definition(slug,name) LOOP
  SELECT count(*), min(id) INTO row_count, permission_id FROM sand_system_menu WHERE code=permission.slug OR slug=permission.slug;
  IF row_count > 1 THEN RAISE EXCEPTION 'SandLicense permission is duplicated: %', permission.slug; END IF;
  IF row_count = 0 THEN
   INSERT INTO sand_system_menu (parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
   VALUES (page_id,permission.name,permission.slug,permission.slug,3,'','','',100,1,1,CURRENT_TIMESTAMP AT TIME ZONE 'UTC',CURRENT_TIMESTAMP AT TIME ZONE 'UTC')
   RETURNING id INTO permission_id;
  ELSIF NOT EXISTS (SELECT 1 FROM sand_system_menu WHERE id=permission_id AND parent_id=page_id AND code=permission.slug AND slug=permission.slug AND type=3 AND path='' AND component='' AND delete_time IS NULL) THEN
   RAISE EXCEPTION 'SandLicense permission ownership conflicts: %', permission.slug;
  END IF;
 END LOOP;
END;
$sand_license_menu$;
-- SAND_LICENSE_MENU_END
