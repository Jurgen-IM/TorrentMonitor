<?php
$dir = dirname(__FILE__).'/../';
include_once $dir.'class/Database.class.php';

$rss = Database::getSetting('rss');

if (!$rss) {
    echo '<h1>RSS disabled</h1>';
    exit;
}

header('Content-type: text/xml; charset=utf-8');

// === НАСТРОЙКИ ===
$maxCount = 1024;
$_Del_torrent_files_older_1month = true;
$_Hide_prev_files_older_week = true;

$url = Database::getSetting('serverAddress');
if (empty($url)) $url = 'http://' . $_SERVER["HTTP_HOST"] . '/';

$Torrents_dir = $dir . 'torrents/';

// === ФУНКЦИЯ ПОИСКА ПОСТЕРОВ ===
// === РЕАЛИЗОВАНО ТОЛЬКО ДЛЯ LOSTFILM ===
function getPoster($tr, $name) {
    static $trRss = null;

	// Нормализация названия из БД
	// Заменяем все последовательности точек, пробелов и подчеркиваний на единую группу [\s.]+
    // Сначала разбиваем строку по разделителям
    $parts = preg_split('/[\s._]+/', $name);
    // Затем экранируем каждый фрагмент и соединяем их с шаблоном
    $normalizedName = implode('[\\s.]+', array_map('preg_quote', $parts)); 
	// Теперь 'The.Legend of Vox Machina' превратится в 'The[\s.]+Legend[\s.]+of[\s.]+Vox[\s.]+Machina'   
    
    if ($trRss === null) {
        if (stripos($tr, 'lostfilm')!==false) {
            $trRss = @file_get_contents('https://www.lostfilm.tv/rss.xml');
        }
    }

    if ($trRss) {
        if (stripos($tr, 'lostfilm')!==false) {
			// Поиск тега <img> в CDATA и извлечение src
			if (preg_match('/<title>.*?' . $normalizedName . '.*?<\/title>.*?<description><!\\[CDATA\\[.*?<img[^>]+src="([^"]+)".*?\\]\\]><\/description>/is', $trRss, $matches)) {
				$src = $matches[1];
				// Преобразование относительного URL в абсолютный
				if (strpos($src, '//') === 0) {
					$src = 'https:' . $src;
				} elseif (strpos($src, '/') === 0) {
					$src = 'https://www.lostfilm.tv' . $src;
				}
				return '<description><![CDATA[<img src="' . $src . '" alt="" /><br />]]></description>';
			}
		}
    }

    return '';
}

// === ПОЛУЧЕНИЕ ДАННЫХ ===
$torrentsList = Database::getTorrentsList('name');

$xml = new DomDocument('1.0', 'utf-8');
$xml->formatOutput = true;

$rssRoot = $xml->appendChild($xml->createElement('rss'));
$rssRoot->setAttribute('version', '0.91');

$channel = $rssRoot->appendChild($xml->createElement('channel'));

$title = $channel->appendChild($xml->createElement('title'));
$title->appendChild($xml->createTextNode('TorrentMonitor RSS (ICEd)'));

$link = $channel->appendChild($xml->createElement('link'));
$link->appendChild($xml->createTextNode($url . 'rss/'));

$language = $channel->appendChild($xml->createElement('language'));
$language->appendChild($xml->createTextNode('ru'));

$lastBuild = $channel->appendChild($xml->createElement('lastBuildDate'));
$lastBuild->appendChild($xml->createTextNode(date("r")));

$count = 0;
$processedFiles = [];

if ($torrentsList) {
    foreach ($torrentsList as $row) {
        if ($count >= $maxCount) break;

        $_Tr = $row['tracker'];
        $_Name = $row['name'];
        $_Id = $row['torrent_id'];
        $_Ep = $row['ep'];
        $_Time = $row['timestamp'];

        if (empty($_Time) || $_Time == '2000-01-01 00:00:00' || $_Time == '0000-00-00 00:00:00') continue;

        $DB_Time_stamp = strtotime($_Time);
        if (!$DB_Time_stamp) continue;

        $Link_file = null;
        $Final_FileTime = $DB_Time_stamp; // По умолчанию берем время из БД

        // === ПОДГОТОВКА ПАРАМЕТРОВ ПОИСКА ===
        $pattern = '';
        $isIdType = !empty($_Id);
        $targetSnEn = '';

        if ($isIdType) {
            // Для ID: ищем [Tracker]_[ID]_[Timestamp].torrent
            $maskBase = preg_quote("[" . $_Tr . "]_" . $_Id, '/');
            $pattern = '/' . $maskBase . '_(\d+)\.torrent$/i';
        } else {
            // Для серий: ищем [Tracker]_Name.S01E05*.torrent
            $_DotName = preg_replace('/[\'\:]/', '', $_Name);
            $_DotName = preg_replace('/\s+/', '.', $_DotName);
            $_DotName = preg_replace('/\.+/', '.', $_DotName);

            $targetSnEn = strtoupper($_Ep); // Целевой S01E05 из БД
            $maskBase = preg_quote("[" . $_Tr . "]_" . $_DotName, '/');
            $pattern = '/' . $maskBase . '\..*\.torrent$/i'; // Ищем все файлы с таким именем
        }

        $foundFile = null;
        $isExactMatch = false;
        $fileMTime = 0;

        // === СКАНИРОВАНИЕ ПАПКИ ===
        $candidates = []; // Массив для хранения всех подходящих файлов
        $filesToDelete = []; // Массив файлов на удаление

        if (is_dir($Torrents_dir)) {
            $files = scandir($Torrents_dir);
            foreach ($files as $f) {
                if ($f == '.' || $f == '..') continue;

                if (preg_match($pattern, $f, $matches)) {
                    $filePath = $Torrents_dir . $f;
                    $fileMTime = filemtime($filePath);
                    $ageSeconds = time() - $fileMTime;

                    $isMatch = false;
                    $isCandidate = false;

                    if ($isIdType) {
                        // ЛОГИКА ДЛЯ ID
                        $fileTimestamp = intval($matches[1]);
                        if ($fileTimestamp == $DB_Time_stamp) {
                            $isMatch = true;
                            $isCandidate = true;
                        } else {
                            // Не совпадает по времени -> проверяем возраст для удаления
                            if ($_Del_torrent_files_older_1month && $ageSeconds > 2592000) {
                                $filesToDelete[] = $filePath;
                            } elseif ($_Hide_prev_files_older_week && $ageSeconds > 604800) {
                                // Скрыть из RSS, но не удалять (если настроено так)
                            } else {
                                // Старая версия, но еще "свежая" по времени -> кандидат на запасной вариант
                                $isCandidate = true;
                            }
                        }
                    } else {
                        // ЛОГИКА ДЛЯ СЕРИАЛОВ
                        if (preg_match('/([Ss][0-9]+[Ee][0-9]+)/', $f, $epMatches)) {
                            $fileSnEn = strtoupper($epMatches[1]);
                            // Нормализация
                            $normFileSnEn = $fileSnEn;
                            $normTargetSnEn = $targetSnEn;
                            if (preg_match('/S(\d+)E(\d+)/', $fileSnEn, $n)) {
                                $normFileSnEn = 'S' . str_pad($n[1], 2, '0', STR_PAD_LEFT) . 'E' . str_pad($n[2], 2, '0', STR_PAD_LEFT);
                            }
                            if (preg_match('/S(\d+)E(\d+)/', $targetSnEn, $n)) {
                                $normTargetSnEn = 'S' . str_pad($n[1], 2, '0', STR_PAD_LEFT) . 'E' . str_pad($n[2], 2, '0', STR_PAD_LEFT);
                            }

                            if ($normFileSnEn === $normTargetSnEn) {
                                $isMatch = true;
                                $isCandidate = true;
                            } else {
                                // Не совпадает по серии -> проверяем возраст для удаления
                                if ($_Del_torrent_files_older_1month && $ageSeconds > 2592000) {
                                    // Удаляем, если старше месяца
                                    $filesToDelete[] = $filePath;
                                } elseif ($_Hide_prev_files_older_week && $ageSeconds > 604800) {
                                    // Не добавляем в кандидаты, если старше недели и флаг включен
                                    // (файл не будет отображаться в RSS)
                                } else {
                                    // Добавляем в кандидаты, если:
                                    // - младше месяца, ИЛИ
                                    // - флаг _Hide_prev_files_older_week выключен, ИЛИ
                                    // - файл младше недели
                                    $isCandidate = true;
                                }
                            }
                        } else {
                             // Нет номера серии в имени -> удаляем если старый
                             if ($_Del_torrent_files_older_1month && $ageSeconds > 2592000) {
                                $filesToDelete[] = $filePath;
                             }
                        }
                    }

                    if ($isCandidate) {
                        $candidates[] = [
                            'name' => $f,
                            'isMatch' => $isMatch,
                            'mtime' => $fileMTime
                        ];
                    }
                }

                // Проверка на переименование старого формата (без timestamp)
                if ($isIdType) {
                    $oldFormatName = "[" . $_Tr . "]_" . $_Id . ".torrent";
                    if ($f === $oldFormatName) {
                        // Это старый файл, проверяем его возраст
                        $filePath = $Torrents_dir . $f;
                        $ageSeconds = time() - filemtime($filePath);

                        // Если есть новый файл с timestamp, этот старый можно удалять сразу если он старше месяца
                        // Но сначала попробуем переименовать, если он актуален по времени БД?
                        // В данном контексте: если мы здесь, значит паттерн с timestamp не сработал на этом файле.
                        // Попытка переименования:
						$newName = "[" . $_Tr . "]_" . $_Id . "_" . $DB_Time_stamp . ".torrent";
						$newFilePath = $Torrents_dir . $newName;

						// Проверяем, что целевого файла не существует и время модификации старого файла близко к времени в БД
						if (!file_exists($newFilePath)) {
							$fileMTime = filemtime($filePath);
							$timeDiff = abs($fileMTime - $DB_Time_stamp);
    
							// Если разница во времени менее 2 часа (7200 секунд), считаем время близким
							if ($timeDiff < 7200) {
								if (@rename($filePath, $newFilePath)) {
									$filesToDelete = array_filter($filesToDelete, function($val) use ($filePath) { return $val !== $filePath; });
									$candidates[] = ['name' => $newName, 'isMatch' => true, 'mtime' => $fileMTime];
									continue; // Перейти к следующему файлу
								}
							}
						}

						// Если переименование не удалось, пометить для удаления при необходимости
						if ($_Del_torrent_files_older_1month && $ageSeconds > 2592000) {
							$filesToDelete[] = $filePath;
						}   
                    }
                }
            }
        }

        // 1. СНАЧАЛА УДАЛЯЕМ СТАРЫЕ ФАЙЛЫ
        foreach ($filesToDelete as $delFile) {
            if (file_exists($delFile)) {
                @unlink($delFile);
                // echo "Deleted: " . basename($delFile) . "\n"; // Для отладки
            }
        }

        // 2. ДОБАВЛЯЕМ ВСЕ ПОДХОДЯЩИЕ ФАЙЛЫ В RSS
        // Сначала добавляем точные совпадения
        $exactMatches = array_filter($candidates, function($c) { return $c['isMatch']; });
        // Сортируем по свежести (свежие первее)
        usort($exactMatches, function($a, $b) { return $b['mtime'] - $a['mtime']; });

        foreach ($exactMatches as $match) {
            $foundFile = $match['name'];
            $Final_FileTime = $match['mtime'];
            $isExactMatch = true;

            // === ФОРМИРОВАНИЕ ЗАГОЛОВКА ===
            $Title = "[" . $_Tr . "] " . $_Name;

            if (!$isIdType && !empty($_Ep)) {
                if (preg_match('/([Ss][0-9]+[Ee][0-9]+)/', $foundFile, $m)) {
                    $sEn = strtoupper($m[1]);
                    if (preg_match('/S(\d+)E(\d+)/', $sEn, $n)) {
                        $sEn = 'S' . str_pad($n[1], 2, '0', STR_PAD_LEFT) . 'E' . str_pad($n[2], 2, '0', STR_PAD_LEFT);
                    }
                    $Title .= " [" . $sEn . "]";
                }
            } else {
                $Title .= " " . $DB_Time_stamp;
            }

            $Title = preg_replace('/[\\\\\/:*?"<>|.]/', ' ', $Title);

            // === ПОСТЕРЫ ===
            $PosterXML = getPoster($_Tr, $_Name);

            // === XML ITEM ===
            $item = $channel->appendChild($xml->createElement('item'));

            $iTitle = $item->appendChild($xml->createElement('title'));
            $iTitle->appendChild($xml->createTextNode($Title));

            $iPubDate = $item->appendChild($xml->createElement('pubDate'));
            $pubTimestamp = $isExactMatch ? $DB_Time_stamp : $Final_FileTime;
            $iPubDate->appendChild($xml->createTextNode(date("r", $pubTimestamp)));

            $iLink = $item->appendChild($xml->createElement('link'));
            $iLink->appendChild($xml->createTextNode($url . 'torrents/' . $foundFile));

            if (!empty($PosterXML)) {
                $fragment = $xml->createDocumentFragment();
                $fragment->appendXML($PosterXML);
                $item->appendChild($fragment);
            }

            $count++;
            if ($count >= $maxCount) break;
        }

        // Затем добавляем неточные совпадения (старые версии), если есть место
        if ($count < $maxCount) {
            $inexactMatches = array_filter($candidates, function($c) { return !$c['isMatch']; });
            usort($inexactMatches, function($a, $b) { return $b['mtime'] - $a['mtime']; });

            foreach ($inexactMatches as $match) {
                $foundFile = $match['name'];
                $Final_FileTime = $match['mtime'];
                $isExactMatch = false;

            // === ФОРМИРОВАНИЕ ЗАГОЛОВКА ===
            $Title = "[" . $_Tr . "] " . $_Name;

            if (!$isIdType && !empty($_Ep)) {
                if (preg_match('/([Ss][0-9]+[Ee][0-9]+)/', $foundFile, $m)) {
                    $sEn = strtoupper($m[1]);
                    if (preg_match('/S(\d+)E(\d+)/', $sEn, $n)) {
                        $sEn = 'S' . str_pad($n[1], 2, '0', STR_PAD_LEFT) . 'E' . str_pad($n[2], 2, '0', STR_PAD_LEFT);
                    }
                    $Title .= " [" . $sEn . "]";
                }
            } else {
                $Title .= " " . $DB_Time_stamp;
            }

            $Title = preg_replace('/[\\\\\/:*?"<>|.]/', ' ', $Title);

            // === ПОСТЕРЫ ===
            $PosterXML = getPoster($_Tr, $_Name);

            // === XML ITEM ===
            $item = $channel->appendChild($xml->createElement('item'));

            $iTitle = $item->appendChild($xml->createElement('title'));
            $iTitle->appendChild($xml->createTextNode($Title));

            $iPubDate = $item->appendChild($xml->createElement('pubDate'));
            $pubTimestamp = $isExactMatch ? $DB_Time_stamp : $Final_FileTime;
            $iPubDate->appendChild($xml->createTextNode(date("r", $pubTimestamp)));

            $iLink = $item->appendChild($xml->createElement('link'));
            $iLink->appendChild($xml->createTextNode($url . 'torrents/' . $foundFile));

            if (!empty($PosterXML)) {
                $fragment = $xml->createDocumentFragment();
                $fragment->appendXML($PosterXML);
                $item->appendChild($fragment);
            }

            $count++;
            if ($count >= $maxCount) break;
            }
        }
    }
}

echo $xml->saveXML();
?>
