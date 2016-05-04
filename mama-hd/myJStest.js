func1.test(function(){	
	chrome.tabs.executeScript(null, {file: "bundle.js"});
});

func2.test(
	function(details) {
		return {requestHeaders: [
			{name:'Referer', value:'http://static.youku.com/'},
			{name:'Cookie', value:'__ysuid'+new Date().getTime()/1e3},
		]};
	},
	{urls: ["http://play.youku.com/*"]},
	["blocking", "requestHeaders"]
);

chrome.webRequest.onBeforeSendHeaders.addListener(
	function(details) {
		return {requestHeaders: [
			{name:'Referer', value:'http://static.youku.com/'},
			{name:'Cookie', value:'__ysuid'+new Date().getTime()/1e3},
		]};
	},
	{urls: ["http://play.youku.com/*"]},
	["blocking", "requestHeaders"]
);

