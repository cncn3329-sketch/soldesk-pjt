<?php
// s3_upload.php
require_once __DIR__ . "/vendor/autoload.php"; // composer로 aws sdk 설치

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

function s3_client(): S3Client {
  $cfg = require __DIR__ . "/s3_config.php";

  return new S3Client([
    "version" => "latest",
    "region"  => $cfg["region"],
    "credentials" => [
      // ✅ config 키 이름: access_key / secret_key
      "key"    => $cfg["access_key"],
      "secret" => $cfg["secret_key"],
    ],
  ]);
}

/**
 * @param array  $files   $_FILES["..."] 배열
 * @param string $prefix  "tasks" 또는 "results"
 * @return array 업로드된 S3 URL들(배열)
 */
function upload_images_to_s3(array $files, string $prefix): array {
  $cfg = require __DIR__ . "/s3_config.php";
  $bucket = $cfg["bucket"];

  $s3 = s3_client();

  $saved = [];
  if (!isset($files["name"]) || !is_array($files["name"])) return $saved;

  for ($i = 0; $i < count($files["name"]); $i++) {
    if (($files["error"][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;

    $tmp  = $files["tmp_name"][$i] ?? "";
    $name = $files["name"][$i] ?? "image";
    if (!$tmp || !is_uploaded_file($tmp)) continue;

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ["jpg","jpeg","png","webp","gif"], true)) continue;

    $key = rtrim($prefix, "/") . "/" . date("Ymd_His") . "_" . bin2hex(random_bytes(6)) . "." . $ext;

    try {
      $s3->putObject([
        "Bucket"      => $bucket,
        "Key"         => $key,
        "SourceFile"  => $tmp,
        "ContentType" => mime_content_type($tmp) ?: "application/octet-stream",
        // 공개로 바로 띄울거면 아래 주석 해제
        // "ACL" => "public-read",
      ]);

      // ✅ URL 생성(공개 버킷/ACL public-read면 바로 접근됨)
      $url = $s3->getObjectUrl($bucket, $key);
      $saved[] = $url;

    } catch (AwsException $e) {
      // 필요하면 로그
      // error_log($e->getMessage());
      continue;
    }
  }

  return $saved;
}

/**
 * ✅ 여러 장(배열) URL을 받아서 S3에서 "배치 삭제"
 * - 한 작업(task)에 사진 여러 장 있는 구조에 맞음
 * - URL에 쿼리스트링(서명) 붙어도 Key 추출됨
 * - 중복 제거 + 1000개 단위 청크 처리(S3 제한 대응)
 */
function delete_images_from_s3(array $urls): void {
  $cfg = require __DIR__ . "/s3_config.php";
  $bucket = $cfg["bucket"];
  $region = $cfg["region"] ?? "";

  $s3 = s3_client();

  // 1) URL -> Key 변환
  $keys = [];
  foreach ($urls as $u) {
    if (!is_string($u)) continue;
    $u = trim($u);
    if ($u === "") continue;

    $key = s3_key_from_url($u, $bucket, $region);
    if ($key !== "") $keys[] = $key;
  }

  // 2) 중복 제거
  $keys = array_values(array_unique($keys));
  if (empty($keys)) return;

  // 3) 1000개 단위로 나눠서 deleteObjects 호출
  $chunks = array_chunk($keys, 1000);

  foreach ($chunks as $chunk) {
    $objects = array_map(function($k){
      return ["Key" => $k];
    }, $chunk);

    try {
      $s3->deleteObjects([
        "Bucket" => $bucket,
        "Delete" => [
          "Objects" => $objects,
          "Quiet"   => true,
        ],
      ]);
    } catch (AwsException $e) {
      // 필요하면 로그
      // error_log($e->getMessage());
      continue;
    }
  }
}

/**
 * ✅ S3 URL에서 Key 추출
 * 지원 형태:
 * - https://bucket.s3.ap-northeast-2.amazonaws.com/tasks/xxx.jpg  => tasks/xxx.jpg
 * - https://s3.ap-northeast-2.amazonaws.com/bucket/tasks/xxx.jpg  => tasks/xxx.jpg
 * - https://bucket.s3.amazonaws.com/tasks/xxx.jpg                => tasks/xxx.jpg
 * - https://s3.amazonaws.com/bucket/tasks/xxx.jpg                => tasks/xxx.jpg
 * - 커스텀 도메인/CloudFront => path를 key로 간주(최대한 삭제 시도)
 */
function s3_key_from_url(string $url, string $bucket, string $region = ""): string {
  $p = parse_url($url);
  if (!$p) return "";

  $host = strtolower($p["host"] ?? "");
  $path = $p["path"] ?? "";
  $path = ltrim($path, "/");

  if ($path === "") return "";

  $bucketLower = strtolower($bucket);

  // 1) virtual-hosted style: bucket.s3.<region>.amazonaws.com/key
  //    또는 bucket.s3.amazonaws.com/key
  if ($host !== "" && stripos($host, $bucketLower . ".s3.") === 0) {
    return $path;
  }
  if ($host !== "" && stripos($host, $bucketLower . ".s3.amazonaws.com") === 0) {
    return $path;
  }

  // 2) path-style: s3.<region>.amazonaws.com/bucket/key
  //    또는 s3.amazonaws.com/bucket/key
  $prefix = $bucket . "/";
  if (stripos($path, $prefix) === 0) {
    return substr($path, strlen($prefix));
  }

  // 3) (추가) region을 알고 있고 host가 s3.<region>.amazonaws.com 같은 경우,
  //    path-style을 더 공격적으로 처리해볼 수도 있음(이미 2번이 대부분 커버)

  // 4) 그 외(커스텀 도메인 등) -> path를 key로 시도
  return $path;
}
