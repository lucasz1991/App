"""Pinned695x151 intrinsic glyph registration; no I/O except source reads."""
from __future__ import annotations
import hashlib,json
from pathlib import Path
from PIL import Image
APP=Path(__file__).resolve().parents[2]
SIZE=(695,151)
def components(image):
 alpha=image.getchannel('A');pixels=alpha.load();seen=set();groups=[]
 for y in range(74,133):
  for x in range(695):
   if (x,y)in seen or not pixels[x,y]:continue
   todo=[(x,y)];seen.add((x,y));points=set()
   while todo:
    u,v=todo.pop();points.add((u,v))
    for q,w in [(u-1,v),(u+1,v),(u,v-1),(u,v+1)]:
     if 0<=q<695 and 74<=w<133 and(q,w)not in seen and pixels[q,w]:seen.add((q,w));todo.append((q,w))
   groups.append(points)
 return groups

def bbox(points):
 return [min(x for x,y in points),min(y for x,y in points),max(x for x,y in points)+1,max(y for x,y in points)+1] if points else None

def dilate(points):return {(u,v)for x,y in points for u in [x-1,x,x+1]for v in [y-1,y,y+1]if 0<=u<695 and 0<=v<151}

def register(frame,reference,dx):
 groups=components(frame);result={};scores=[]
 for key,base in reference.items():
  wanted={(x+dx,y)for x,y in base if 0<=x+dx<695}
  if not wanted:result[key]=set();continue
  wide=dilate(wanted);selected=[]
  for points in groups:
   intersection=len(points&wide)
   if intersection and intersection/len(points)>=.93:selected.append(points)
  actual=set().union(*selected)if selected else set()
  coverage=len(wanted&dilate(actual))/len(wanted)if actual else 0
  ratio=len(actual)/len(wanted)
  if coverage<.92 or not .65<=ratio<=1.5:return None
  result[key]=actual;scores.append((len(actual&wanted)/len(actual|wanted),coverage))
 if any(result[a]&result[b]for a in result for b in result if a!=b):return None
 return result,sum(s[0]for s in scores)/max(1,len(scores))

def run_registration():
 report={'scope':'Private component registration on existing pinned raster frames; no production outputs','variants':{}}
 all_frames={};all_registrations={};all_references={}
 for variant in ['light','dark','light-mirrored','dark-mirrored']:
  image_path=APP/'public/mail-assets'/('zug-dampf-outlook-intrinsic-v1-'+variant+'.png')
  gif_path=image_path.with_suffix('.gif')
  still=Image.open(image_path).convert('RGBA')
  if still.size!=SIZE:raise ValueError('Intrinsic train canvas changed')
  mirrored=variant.endswith('mirrored')
  nominal={'R-main':(537,88,579,130),'T':(537,96,565,130),'R-stem':(537,106,545,130)}
  if mirrored:nominal={key:(695-b[2],b[1],695-b[0],b[3])for key,b in nominal.items()}
  groups=components(still);reference={}
  for key,b in nominal.items():
   ranked=sorted((sum(abs(a-z)for a,z in zip(bbox(g),b)),index)for index,g in enumerate(groups)if len(g)>40)
   if not ranked or ranked[0][0]>4 or (len(ranked)>1 and ranked[1][0]<=ranked[0][0]):raise ValueError('Ambiguous standing glyph registration: '+variant+'/'+key)
   reference[key]=groups[ranked[0][1]]
  all_references[variant]=reference
  frames=[];rows=[];registrations=[]
  with Image.open(gif_path)as gif:
   if gif.n_frames!=36:raise ValueError('Approved train frame count changed')
   for n in range(gif.n_frames):
    gif.seek(n);frame=gif.convert('RGBA');frames.append(frame)
    a=frame.getchannel('A');pix=a.load()
    xs=[x for y in range(112,151)for x in range(695)if pix[x,y]]
    if not xs:
     rows.append({'frame':n,'duration':gif.info.get('duration'),'visible_badge_pixels':0,'dx':None,'reason':'no body pixels visible'});registrations.append({k:set()for k in reference});continue
    dx=(min(xs) if mirrored else max(xs)+1-695)
    candidates=[]
    for shift in range(dx-2,dx+3):
     match=register(frame,reference,shift)
     if match is not None:candidates.append((match[1],-abs(shift-dx),shift,match[0]))
    if not candidates:raise ValueError('Moving glyph registration failed: '+variant+'/'+str(n))
    candidates.sort(key=lambda r:(r[0],r[1]),reverse=True);score,_,shift,registration=candidates[0]
    rows.append({'frame':n,'duration':gif.info.get('duration'),'dx':shift,'estimated_body_dx':dx,'score':score,
     'visible_badge_pixels':sum(len(p)for p in registration.values()),'glyphs':{key:{'pixels':len(points),'bbox':bbox(points),'mask_sha256':hashlib.sha256(json.dumps(sorted(points)).encode()).hexdigest()}for key,points in registration.items()}})
    registrations.append(registration)
  report['variants'][variant]={'png_sha256':hashlib.sha256(image_path.read_bytes()).hexdigest(),'gif_sha256':hashlib.sha256(gif_path.read_bytes()).hexdigest(),'reference':{key:{'pixels':len(points),'bbox':bbox(points)}for key,points in reference.items()},'frames':rows}
  all_frames[variant]=frames;all_registrations[variant]=registrations
 return report,all_frames,all_registrations,all_references
