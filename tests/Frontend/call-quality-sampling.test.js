import test from 'node:test';
import assert from 'node:assert/strict';
import { setImmediate } from 'node:timers/promises';
import { observeMediaQuality, classifyUpload, classifyDownload } from '../../resources/js/call-quality-sampling.js';

function publication(source, rows, kind = 'video') {
    return {
        trackSid: source, source, kind, isDesired: true, isEnabled: true,
        track: {
            mediaStreamTrack: {}, getSourceTrackSettings: () => ({width: 1280, height: 720}),
            getRTCStatsReport: async () => new Map(rows().map(row => [row.id,row])),
        },
    };
}

function room(local, remote) {
    const member = tracks => ({trackPublications: new Map(tracks.map(pub => [pub.trackSid,pub]))});
    return {localParticipant: member(local),remoteParticipants: new Map([['guest',member(remote)]])};
}

test('sampling separates camera, receiver, audio and transport evidence; pressure uses independent directions', async t => {
    t.mock.timers.enable({apis: ['setTimeout']});
    let seq = 0;
    const currentTime = () => 1000 + 3000 * seq;
    const selector = {id:'transport', type:'transport', selectedCandidatePairId:'candidate'};
    const pair = {id:'candidate',type:'candidate-pair',currentRoundTripTime:.1,availableOutgoingBitrate:800000,ip:'192.0.2.42'};
    const uplink = publication('camera', () => [
        {id:'camera',type:'outbound-rtp',kind:'video',ssrc:22,timestamp:currentTime(),transportId:selector.id,
            frameWidth:1280,frameHeight:720,bytesSent:1e6 + 3e5*seq,framesEncoded:400+45*seq,
            qualityLimitationDurations:{cpu:6+2*seq,bandwidth:0},remoteId:'feedback'},
        {id:'feedback', timestamp:currentTime(), packetsLost:20+seq,packetsReceived:500+99*seq, jitter:.004},
        selector,pair,
    ]);
    const remote = publication('camera-2', () => [
        {id:'rx',type:'inbound-rtp',kind:'video',timestamp:currentTime(),bytesReceived:5000+3e4*seq,
            packetsLost:seq*6,packetsReceived:seq*94,framesDecoded:seq*90,framesDropped:seq*10,
            totalDecodeTime:seq*2.7,totalFreezesDuration:.4*seq,frameWidth:640,frameHeight:360},
    ]);
    const mic = publication('microphone', () => [
        {id:'audio',type:'inbound-rtp',kind:'audio',timestamp:currentTime(),bytesReceived:500+seq*25000,
            concealedSamples:seq*288,totalSamplesReceived:seq*144000,jitterBufferDelay:seq*6,jitterBufferEmittedCount:seq*150},
    ],'audio');
    const samples = [];
    const stop = observeMediaQuality(room([uplink],[remote,mic]), rows => samples.push(rows));
    await setImmediate();
    assert.equal(classifyUpload(samples[0][0],25),'unknown');
    seq++;
    t.mock.timers.tick(3000);
    await setImmediate();
    const [up, down, audio] = samples[1];
    assert.equal(up.kbps,800);
    assert.equal(up.availableOutgoingKbps,800);
    assert.equal(up.rttMs,100);
    assert.equal(up.cpuFraction,2/3);
    assert.equal(up.loss,1);
    assert.equal(classifyUpload(up,25),'pressure');
    assert.equal(up.observationKey,samples[0][0].observationKey);
    assert.equal(down.dropRatio,.1);
    assert.equal(down.loss,6);
    assert.equal(down.decodeMs,30);
    assert.equal(classifyDownload(down),'pressure');
    assert.equal(classifyUpload(down,25),'unknown');
    assert.equal(audio.concealmentRatio,.002);
    assert.equal(audio.bufferMs,40);
    assert.ok(!JSON.stringify(samples).includes('192.0.2.'));
    stop();
    t.mock.timers.tick(6000);
    await setImmediate();
    assert.equal(samples.length,2);
});

test('stale intervals, reset counters, changed SSRC or underlying capture and paused tracks start a new baseline', async t => {
    t.mock.timers.enable({apis: ['setTimeout']});
    let timestamp = 3000, bytes = 1000, frames = 20, ssrc = 12, lost = 3, dropped = 0;
    const pub = publication('remote', () => [{id:'rx',kind:'video',type:'inbound-rtp',ssrc,timestamp,bytesReceived:bytes,
        framesDropped:dropped,framesDecoded:frames,totalFreezesDuration:0,packetsLost:lost,packetsReceived:frames}]);
    let rows = [];
    const stop = observeMediaQuality(room([],[pub]), value => {rows = value;});
    await setImmediate();
    async function advance(ms=3000) {
        timestamp += ms; bytes += 50; frames += 15;
        t.mock.timers.tick(3000);
        await setImmediate();
        return rows[0];
    }
    let row = await advance();
    assert.equal(classifyDownload(row),'stable');
    lost--; row=await advance();
    assert.equal(row.loss,null);
    assert.equal(classifyDownload(row),'unknown');
    const firstEpoch = row.observationKey;
    row=await advance(9000);
    assert.equal(row.intervalMs,null);
    assert.notEqual(row.observationKey, firstEpoch);
    bytes=0; row=await advance();
    assert.equal(row.kbps,null);
    ssrc++; row=await advance();
    assert.equal(row.intervalMs,null);
    pub.track.mediaStreamTrack={}; row=await advance();
    assert.equal(row.intervalMs,null);
    pub.track.streamState='paused';
    assert.equal(await advance(),undefined);
    pub.track.streamState='active';
    assert.equal((await advance()).intervalMs,null);
    stop();
});

test('low frame rate alone never demotes a static share, missing metrics never imply health', () => {
    const share = {source:'screen_share',kind:'video',local:true,intervalMs:3000,fps:1,cpuFraction:0,bandwidthFraction:0};
    assert.equal(classifyUpload(share,12),'unknown');
    assert.equal(classifyUpload({...share,fps:12,cpuFraction:null},12),'unknown');
    assert.equal(classifyUpload({...share,fps:12},12),'stable');
    const receive = {kind:'video',local:false,intervalMs:3000,dropRatio:null,loss:6,frozen:.25};
    assert.equal(classifyDownload(receive),'pressure');
    assert.equal(classifyDownload({...receive,loss:null}),'unknown');
    assert.equal(classifyDownload({...receive,loss:0,frozen:0}),'unknown');
});
