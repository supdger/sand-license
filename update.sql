-- Menu-only navigation upgrade. API grants remain intact; new navigation inherits only matching old list grants.
-- SAND_LICENSE_MENU_BEGIN
DO $sand_license_menu$
DECLARE
 root_id bigint;
 center_id bigint;
 center_status integer;
 page_id bigint;
 permission_id bigint;
 list_permission_id bigint;
 list_status integer;
 row_count integer;
 page record;
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
 SELECT count(*), min(id) INTO row_count, center_id FROM sand_system_menu WHERE code = 'SandLicenseCenter';
 IF row_count > 1 THEN RAISE EXCEPTION 'SandLicense center menu is duplicated'; END IF;
 IF row_count = 0 THEN
  INSERT INTO sand_system_menu (parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
  VALUES (root_id,'商业许可','SandLicenseCenter','',2,'index','/plugin/sand-license/index/index','ri:key-2-line',100,1,1,CURRENT_TIMESTAMP AT TIME ZONE 'UTC',CURRENT_TIMESTAMP AT TIME ZONE 'UTC')
  RETURNING id INTO center_id;
 ELSIF NOT EXISTS (SELECT 1 FROM sand_system_menu WHERE id=center_id AND parent_id=root_id AND type=2 AND path='index' AND component='/plugin/sand-license/index/index' AND slug='' AND delete_time IS NULL) THEN
  RAISE EXCEPTION 'SandLicense center menu ownership conflicts';
 END IF;
 SELECT status INTO center_status FROM sand_system_menu WHERE id=center_id;
 UPDATE sand_system_menu SET is_hidden=1, update_time=CURRENT_TIMESTAMP AT TIME ZONE 'UTC' WHERE id=center_id AND is_hidden<>1;
 -- Keep all type=3 API permissions. Only newly inserted pages inherit old matching list navigation.
 FOR page IN SELECT * FROM (VALUES
  ('SandLicenseProduct','软件产品','product','sand_license:product:index','ri:apps-2-line',100),
  ('SandLicensePlan','套餐与功能','plan','sand_license:plan:index','ri:list-check-2',99),
  ('SandLicenseCode','卡密发放','code','sand_license:code:index','ri:ticket-2-line',98),
  ('SandLicenseEntitlement','已发放权益','entitlement','sand_license:entitlement:index','ri:shield-check-line',97),
  ('SandLicenseActivation','设备激活','activation','sand_license:activation:index','ri:computer-line',96),
  ('SandLicenseSkuMapping','商品映射','sku-mapping','sand_license:sku_mapping:index','ri:links-line',95),
  ('SandLicenseFulfillment','履约记录','fulfillment','sand_license:fulfillment:index','ri:file-list-3-line',94),
  ('SandLicenseMembership','会员权益','membership','sand_license:membership:index','ri:vip-crown-line',93),
  ('SandLicenseEvent','操作记录','event','sand_license:event:index','ri:history-line',92)
 ) AS definition(code,name,path,slug,icon,sort) LOOP
  SELECT count(*), min(id) INTO row_count, page_id FROM sand_system_menu WHERE code=page.code;
  IF row_count > 1 THEN RAISE EXCEPTION 'SandLicense page is duplicated: %', page.code; END IF;
  IF row_count = 0 THEN
   SELECT count(*), min(id) INTO row_count, list_permission_id FROM sand_system_menu WHERE code=page.slug OR slug=page.slug;
   IF row_count > 1 THEN RAISE EXCEPTION 'SandLicense list permission is duplicated: %', page.slug; END IF;
   list_status := center_status;
   IF row_count = 1 THEN
    IF NOT EXISTS (SELECT 1 FROM sand_system_menu WHERE id=list_permission_id AND parent_id=center_id AND code=page.slug AND slug=page.slug AND type=3 AND path='' AND component='' AND delete_time IS NULL) THEN
     RAISE EXCEPTION 'SandLicense list permission ownership conflicts: %', page.slug;
    END IF;
    SELECT CASE WHEN center_status=2 THEN 2 ELSE status END INTO list_status FROM sand_system_menu WHERE id=list_permission_id;
   END IF;
   INSERT INTO sand_system_menu (parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
   VALUES (root_id,page.name,page.code,'',2,page.path,'/plugin/sand-license/'||page.path||'/index',page.icon,page.sort,2,list_status,CURRENT_TIMESTAMP AT TIME ZONE 'UTC',CURRENT_TIMESTAMP AT TIME ZONE 'UTC')
   RETURNING id INTO page_id;
   -- Navigation only: no new type=3 action grants and no grants based on the old center.
   INSERT INTO sand_system_role_menu (role_id,menu_id)
   SELECT DISTINCT role_id,page_id FROM sand_system_role_menu WHERE menu_id=list_permission_id
    AND NOT EXISTS (SELECT 1 FROM sand_system_role_menu AS existing WHERE existing.role_id=sand_system_role_menu.role_id AND existing.menu_id=page_id);
  ELSIF NOT EXISTS (SELECT 1 FROM sand_system_menu WHERE id=page_id AND parent_id=root_id AND code=page.code AND slug='' AND type=2 AND path=page.path AND component='/plugin/sand-license/'||page.path||'/index' AND delete_time IS NULL) THEN
   RAISE EXCEPTION 'SandLicense page ownership conflicts: %', page.code;
  END IF;
 END LOOP;
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
  SELECT id INTO STRICT page_id FROM sand_system_menu WHERE path=replace(split_part(permission.slug,':',2),'_','-') AND parent_id=root_id AND type=2 AND code IN ('SandLicenseProduct','SandLicensePlan','SandLicenseCode','SandLicenseEntitlement','SandLicenseActivation','SandLicenseSkuMapping','SandLicenseFulfillment','SandLicenseMembership','SandLicenseEvent') AND delete_time IS NULL;
  SELECT count(*), min(id) INTO row_count, permission_id FROM sand_system_menu WHERE code=permission.slug OR slug=permission.slug;
  IF row_count > 1 THEN RAISE EXCEPTION 'SandLicense permission is duplicated: %', permission.slug; END IF;
  IF row_count = 0 THEN
   INSERT INTO sand_system_menu (parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
   VALUES (page_id,permission.name,permission.slug,permission.slug,3,'','','',100,1,center_status,CURRENT_TIMESTAMP AT TIME ZONE 'UTC',CURRENT_TIMESTAMP AT TIME ZONE 'UTC')
   RETURNING id INTO permission_id;
  ELSIF NOT EXISTS (SELECT 1 FROM sand_system_menu WHERE id=permission_id AND parent_id IN (center_id,page_id) AND code=permission.slug AND slug=permission.slug AND type=3 AND path='' AND component='' AND delete_time IS NULL) THEN
   RAISE EXCEPTION 'SandLicense permission ownership conflicts: %', permission.slug;
  ELSE
   UPDATE sand_system_menu SET parent_id=page_id,update_time=CURRENT_TIMESTAMP AT TIME ZONE 'UTC' WHERE id=permission_id AND parent_id<>page_id;
  END IF;
 END LOOP;
END;
$sand_license_menu$;
-- SAND_LICENSE_MENU_END
