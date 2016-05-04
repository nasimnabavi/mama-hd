
// TODO
// [OK] youku support
// [OK] tudou support
// [OK] video player shortcut
// [OK] double buffered problem: http://www.bilibili.com/video/av4376362/index_3.html at 360.0s
//      discontinous audio problem: http://www.bilibili.com/video/av3067286/ at 97.806,108.19
//      discontinous audio problem: http://www.bilibili.com/video/av1965365/index_6.html at 51.806
// [OK] fast start
// [OK] open twice
// [OK] http://www.bilibili.com/video/av3659561/index_57.html: Error: empty range, maybe video end
// [OK] http://www.bilibili.com/video/av3659561/index_56.html: First segment too small
// http://www.bilibili.com/video/av1753789/: mediaSource: sourceclose,Failed to execute 'appendBuffer' on 'SourceBuffer'
// double buffered problem: http://www.bilibili.com/video/av4467810/

'use strict'

let mediaSource = require('./mediaSource');
let Nanobar = require('nanobar');
let bilibili = require('./bilibili');
let youku = require('./youku');
let tudou = require('./tudou');
let createPlayer = require('./player');
let flashBlocker = require('./flashBlocker');
let flvdemux = require('./flvdemux');

let nanobar = new Nanobar();

let style = document.createElement('style');
style.innerHTML = `
.nanobar .bar {
	background: #c16c70
}
.nanobar {
	z-index: 2999999
}
`
document.head.appendChild(style);
mediaSource.debug = true;

let getSeeker = url => {
	let seekers = [bilibili, youku, tudou];
	let found = seekers.filter(s => s.testUrl(url));
	return found[0];
}

let playVideo = res => {
	let player = createPlayer();
	let media = mediaSource.bindVideo({
		video:player.video,
		src:res.src,
		duration:res.duration,
	});
	player.streams = media.streams;
	return {player, media};
}

let playUrl = url => {
	let seeker = getSeeker(url)
	if (seeker) {
		flashBlocker();
		nanobar.go(30);
		seeker.getVideos(url).then(res => {
			console.log('getVideosResult:', res);
			if (res) {
				let ctrl = playVideo(res);
				ctrl.player.onStarted = () => nanobar.go(100);
				nanobar.go(60)
			} else {
				throw new Error('cannot play')
			}
		}).catch(e => {
			console.error(e.stack)
			nanobar.go(100);
		});
	}
}

let cmd = {};

cmd.testBuggy2Buf = () => {
	let streams = new mediaSource.Streams(['http://localhost:6060/buggybuf2.flv']);
	streams.probe().then(res => {
		return streams.fetchMediaSegmentsByIndex(74, 77).then(res => {
		});
	}).then(res => {
	})
}
module.exports.testUrl = url => url.match('bilibili.com/')
cmd.testBuggy2Play = () => {
	cmd.ctrl = playVideo({
		src:[
			'http://localhost:6060/buggybuf2.flv',
		],
	});
	setTimeout(() => cmd.ctrl.player.video.currentTime = 350.0, 500);
}

cmd.fetchDiscontAudio = () => {
	// at 209.667
	let streams = new mediaSource.Streams(['http://localhost:6060/discontaudio.flv']);
	streams.probe().then(res => {
		return streams.fetchMediaSegmentsByIndex(40,41);
	}).then(() => {
		return streams.fetchMediaSegmentsByIndex(41,42);
	})
}

cmd.playDiscontAudio = () => {
	cmd.ctrl = playVideo({
		src:[
			'http://localhost:6060/discontaudio.flv',
		],
	});
	setTimeout(() => cmd.ctrl.player.video.currentTime = 51.0, 400);
}

cmd.testPlayerUI = () => {
	cmd.ctrl = playVideo({
		src:[
			'http://localhost:6060/projectindex-0.flv',
			'http://localhost:6060/projectindex-1.flv',
			'http://localhost:6060/projectindex-2.flv',
			'http://localhost:6060/projectindex-3.flv',
		],
		duration: 1420.0,
	});
}

cmd.youku = youku;

cmd.testGetVideos = url => {
	let seeker = getSeeker(url);
	if (!seeker) {
		console.log('seeker not found');
		return;
	}
	seeker.getVideos(url).then(res => console.log(res))
}

cmd.testYouku = () => {
	youku.getVideos('http://v.youku.com/v_show/id_XMTU0NTYzOTIyMA==.html?from=1-1').then(res => console.log(res))
	//youku.testEncryptFuncs()
	//youku.showlog()
}

cmd.playUrl = url => {
	playUrl(url)
}

cmd.testXhr = () => {
	var xhr = new XMLHttpRequest();
	xhr.open('GET', 'http://localhost:6060/projectindex-0.flv');
	setTimeout(() => xhr.abort(), 100);
	xhr.onload = function(e) {
		console.log(this.status);
		console.log(this.response.length);
	}
	xhr.onerror = function() {
		console.log('onerror')
	}
	xhr.send();
}

cmd.testWriteFile = () => {
	chrome.fileSystem.chooseEntry({type:'saveFile'}, (file) => {
		file.createWriter(writer => {
			writer.onwrittend = () => console.log('write complete');
			let u8 = new Uint8Array([1,2,3,4]);
			writer.write(u8);
		});
	});
}

cmd.fetchMediacloseBugVideo= () => {
	let url = 'http://www.bilibili.com/video/av1753789/';
	getSeeker(url).getVideos(url).then(res => {
		let streams = mediaSource.Streams({urls: res.src, fakeDuration: res.duration});
		streams.probeFirst().then(() => {
		});
	})
}

cmd.testfetch = () => {
	let dbp = console.log.bind(console)

	let parser = new flvdemux.InitSegmentParser();
	let total = 0;
	let pump = reader => {
		return reader.read().then(res => {
			if (res.done) {
				dbp('parser: EOF');
				return;
			}
			let chunk = res.value;
			total += chunk.byteLength;
			dbp(`parser: incoming ${chunk.byteLength}`);
			let done = parser.push(chunk);
			if (done) {
				dbp('parser: finished', done);
				reader.cancel();
				return done;
			} else {
				return pump(reader);
			}
		});
	}

	let headers = new Headers();
	headers.append('Range', 'bytes=0-400000');
	fetch(`http://27.221.48.172/youku/65723A1CDA44683D499698466F/030001290051222DE95D6C055EEB3EBFDE3F09-E65E-1E0A-218C-3CDFACC4F973.flv`, {headers}).then(res => pump(res.body.getReader()))
		.then(res => console.log(res));
}

playUrl(location.href);

window.cmd = cmd;

