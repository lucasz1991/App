"""Build immutable branded train media and the transparent-top-trimmed caption.

Python3.12/Pillow12.2/CairoSVG2.9 reproduce the approved bytes. Existing
intrinsic-v1 raster frames supply all train/smoke pixels. Only their separated
R/T glyph components receive colors from the unchanged official SVG. All144
decoded frames are checked outside those exact masks, with alpha, frame times,
loop and disposal preserved. The caption removes only25transparent top rows.

Usage: python scripts/mail/build_branded_outlook_train.py [--check]
No network/application bootstrap. Both asset directories receive new names
only; different existing outputs are refused. --check creates no directories,
files or bytecode. The static /m/ copies are built by the existing linked builder.
"""
from __future__ import annotations
import sys
sys.dont_write_bytecode=True
import argparse,functools,hashlib,io,json,math,os,xml.etree.ElementTree as ET
from collections import Counter
from pathlib import Path
import cairosvg
from PIL import Image,GifImagePlugin,__version__ as PILLOW_VERSION
import branded_train_registration as reg
APP=Path(__file__).resolve().parents[2]
OUTPUT_DIRS=('public/mail-assets','resources/mail-templates/assets')
SVG_SHA256='1353efa81573d39bbc8d19de62d02c3f5bac4c784d1ffe9e6a87dfdb69cdb68f'
CAPTION='outlook-hotline-label-outline-v1.png'
CAPTION_SHA256='003919f6fe18e62a958217d165f9c3a93602f513af78bbd1470aaa6dc95a18ec'
SAMPLE=20
RASTER=42*SAMPLE
rasters={}
SOURCE_PINS={'zug-dampf-outlook-intrinsic-v1-light.gif': '45f4109cf7dbeed8c31c4c8a56bfdd9065b947b4b576596ba4bd6e5373b73fb8', 'zug-dampf-outlook-intrinsic-v1-light.png': '78a1fd22c31977b21286a40b5aafe51b957b63b49f40948b6ed2b21ba0651d4a', 'zug-dampf-outlook-intrinsic-v1-dark.gif': '822dd45cf0529e41d8e1ed9a082904581d76be4a9f25c39240111e11fd3d3554', 'zug-dampf-outlook-intrinsic-v1-dark.png': 'b1bc9d62a6dd026ffc9e6f0c181d1586084104223afa272766ee38371e4072dd', 'zug-dampf-outlook-intrinsic-v1-light-mirrored.gif': '9db04d54004742501156413430e77ccf1b1ed8515018caa9c7d00caedaf63f17', 'zug-dampf-outlook-intrinsic-v1-light-mirrored.png': 'dc6eb47779cca236195052802aac7c1e22dba1f8198fbfbadb5729b504df99d6', 'zug-dampf-outlook-intrinsic-v1-dark-mirrored.gif': '18567efab2a728b8058195b8fef1651e97b2aff8c116261af9698afabdaad4d9', 'zug-dampf-outlook-intrinsic-v1-dark-mirrored.png': '4b6995aa470fb7730c0efa88d95528eb053bf95e3314a9567f2d36084370bd78'}
OUTPUT_PINS={'zug-dampf-outlook-brand-v1-light.png': {'sha256': '0b9b8d55f6a2343198a721915fe2fa2e61df6eb1e52f72bb91f32a4c4a2a5258', 'bytes': 7371}, 'zug-dampf-outlook-brand-v1-light.gif': {'sha256': '47007453902679fbc96eda87bb8701db7f701b2c340d83e9561fa68c4dbd503d', 'bytes': 179636}, 'zug-dampf-outlook-brand-v1-dark.png': {'sha256': 'd8370fc0cc4d87ddad0a076cb5c2468e36ad1f5667966418cbfc2c519bd878b3', 'bytes': 4411}, 'zug-dampf-outlook-brand-v1-dark.gif': {'sha256': '243786c7e426e2f06165858c8a1883cfd5598b72df1247c7cc2b3ff6e41220a5', 'bytes': 130062}, 'zug-dampf-outlook-brand-v1-light-mirrored.png': {'sha256': 'b6d051cacd77535d71366db071b9c077fdbd7d4d487eb369fed2a77fbe142792', 'bytes': 7572}, 'zug-dampf-outlook-brand-v1-light-mirrored.gif': {'sha256': '67d1600699cad2f1f2af69ea59a9d88a7085f26556966f083ab615401e430d39', 'bytes': 178583}, 'zug-dampf-outlook-brand-v1-dark-mirrored.png': {'sha256': '917e8f055d74b6e4b2294df05fb74079cfb5e8526902cc87fda2e260b658861b', 'bytes': 4566}, 'zug-dampf-outlook-brand-v1-dark-mirrored.gif': {'sha256': 'ed21ba08d9d32ede363a30d13d182aa802b426330fd0110c4b0f50b11c960768', 'bytes': 129369}, 'outlook-hotline-label-inktop-v1.png': {'sha256': '37683e55f5469c8593450bfeaeea13ed0b1849976dce15b7479d7095d998540a', 'bytes': 6293}}
max_sampling_distance=0
@functools.lru_cache(maxsize=10000)
def sample(kind,x,y):
 global max_sampling_distance
 image=rasters[kind];px=image.load();cx=round((x+.5)*SAMPLE);cy=round((y+.5)*SAMPLE)
 # Existing GIF thresholding can leave an edge pixel beside vector coverage.
 # Sample the nearest same-glyph vector color; never invent another path.
 for radius in range(0,51):
  points=[(cx,cy)]if radius==0 else [(cx+u,cy+v)for u in range(-radius,radius+1)for v in range(-radius,radius+1)if max(abs(u),abs(v))==radius]
  candidates=[(u,v)for u,v in points if 0<=u<RASTER and 0<=v<RASTER and px[u,v][3]>=200]
  if candidates:
   u,v=min(candidates,key=lambda q:(q[0]-cx)**2+(q[1]-cy)**2)
   max_sampling_distance=max(max_sampling_distance,math.hypot(u-cx,v-cy)/SAMPLE)
   if math.hypot(u-cx,v-cy)/SAMPLE>2.5:raise ValueError('Vector color sample lies outside the approved glyph edge')
   return px[u,v][:3]
 raise AssertionError(('Unexplained glyph pixel',kind,x,y))

def recolor(frame,registration,dx,variant,core):
 new=frame.copy();old=frame.load();dest=new.load();background=(12,16,23)if variant.startswith('dark')else(255,255,255)
 mirrored=variant.endswith('mirrored');denominator=sum((a-b)**2 for a,b in zip(core,background))
 masks=set().union(*registration.values())
 for label,points in registration.items():
  for x,y in points:
   normal_x=694-(x-dx)if mirrored else x-dx
   rgb=sample('T'if label=='T'else'R',normal_x-537,y-88)
   existing=old[x,y]
   coverage=max(0,min(1,sum((a-b)*(c-b)for a,b,c in zip(existing[:3],background,core))/denominator))
   dest[x,y]=tuple(round(c*coverage+b*(1-coverage))for c,b in zip(rgb,background))+(existing[3],)
 return new,masks

def encoded_gif(frames,masks,durations,disposal,loop,old_palette):
 # Keep all original used entries at their old indices. Append only badge colors.
 old_colors=[tuple(old_palette[i:i+3])for i in range(0,9,3)]
 palette_rgb=[]
 for frame,mask in zip(frames,masks):palette_rgb.extend(frame.getpixel(p)[:3]for p in sorted(mask))
 strip=Image.new('RGB',(len(palette_rgb),1));strip.putdata(palette_rgb)
 quant=strip.quantize(colors=253,method=Image.Quantize.MEDIANCUT)
 additional=[]
 for offset in range(0,759,3):
  color=tuple(quant.getpalette()[offset:offset+3])
  if color not in old_colors and color not in additional:additional.append(color)
 palette=old_colors+additional
 if len(palette)>256:raise ValueError('GIF palette exceeds256entries')
 flat=[v for c in palette for v in c]+[0]*(768-len(palette)*3)
 @functools.lru_cache(maxsize=10000)
 def index(color):return min(range(3,len(palette)),key=lambda n:sum((a-b)**2 for a,b in zip(color,palette[n])))
 old_lookup={c:i for i,c in enumerate(old_colors)if i}
 indexed=[]
 for frame,mask in zip(frames,masks):
  data=[]
  for n,(r,g,b,a)in enumerate(frame.get_flattened_data()):
   if not a:data.append(0);continue
   point=(n%695,n//695);data.append(index((r,g,b))if point in mask else old_lookup[(r,g,b)])
  image=Image.frombytes('P',frame.size,bytes(data));image.putpalette(flat);indexed.append(image)
 info={'transparency':0,'background':0,'optimize':False}
 if loop is not None:info['loop']=loop
 header,_=GifImagePlugin.getheader(indexed[0],info=info);blocks=[*header]
 for frame,duration,method in zip(indexed,durations,disposal):blocks.extend(GifImagePlugin.getdata(frame,duration=duration,disposal=method,transparency=0,include_color_table=False,optimize=False))
 blocks.append(b';');return b''.join(blocks),len(palette)

def verify_pixels(old,new,mask):
 changed=outside=alpha=0
 for n,(a,b)in enumerate(zip(old.get_flattened_data(),new.get_flattened_data())):
  if a!=b:
   changed+=1
   if(n%695,n//695)not in mask:outside+=1
  alpha+=a[3]!=b[3]
 if outside or alpha:raise ValueError('Pixels outside the badge or alpha changed: '+str((outside,alpha)))
 return changed


def secure_path(relative):
 path=APP/relative
 if not path.resolve().is_relative_to(APP.resolve()):raise ValueError('Path outside application')
 current=APP
 for part in Path(relative).parts:
  current/=part
  if current.is_symlink()or(getattr(current,'is_junction',lambda:False)()):raise ValueError('Linked asset path refused')
 return path

def build_assets(check=False):
 global rasters,max_sampling_distance
 max_sampling_distance=0;sample.cache_clear();rasters={};before={}
 for folder in OUTPUT_DIRS:
  input_pins={**SOURCE_PINS,**({CAPTION:CAPTION_SHA256}if folder=='public/mail-assets'else{})}
  for name,sha in input_pins.items():
   f=secure_path(folder+'/'+name)
   if not f.is_file()or hashlib.sha256(f.read_bytes()).hexdigest()!=sha:raise ValueError('Pinned input mismatch: '+name)
   before[str(f)]=sha
 svg_path=secure_path('public/rt-brand/rt-logo.svg');svg_before=svg_path.read_bytes();svg=svg_before.replace(b'\r\n',b'\n')
 # Source files may use Windows line endings; SVG markup bytes otherwise match.
 if hashlib.sha256(svg).hexdigest()!=SVG_SHA256:raise ValueError('Official SVG source mismatch')
 for kind,wanted in [('R','R_Red'),('T','T_Dark')]:
  root=ET.fromstring(svg)
  if not any(n.attrib.get('id')==wanted for n in root.iter()):raise ValueError('Official glyph missing')
  for parent in root.iter():
   for node in list(parent):
    if node.attrib.get('id')in ['R_Red','T_Dark']and node.attrib['id']!=wanted:parent.remove(node)
  data=cairosvg.svg2png(bytestring=ET.tostring(root),output_width=RASTER,output_height=RASTER)
  rasters[kind]=Image.open(io.BytesIO(data)).convert('RGBA')
 registration,originals,maps,references=reg.run_registration();output={};stats={}
 for variant,frames in originals.items():
  source=secure_path('public/mail-assets/zug-dampf-outlook-intrinsic-v1-'+variant+'.gif')
  with Image.open(source)as gif:
   old_palette=gif.getpalette();loop=gif.info.get('loop');durations=[];disposal=[]
   for n in range(gif.n_frames):gif.seek(n);durations.append(gif.info.get('duration'));disposal.append(gif.disposal_method)
  if durations!=[250,*([200]*34),5950]:raise ValueError('Approved timeline changed')
  colors=Counter(frames[0].getpixel(p)[:3]for points in references[variant].values()for p in points)
  bg=(12,16,23)if variant.startswith('dark')else(255,255,255)
  core=max(colors,key=lambda color:sum((a-b)**2 for a,b in zip(color,bg)))
  new_frames=[];masks=[]
  for n,(frame,glyphs)in enumerate(zip(frames,maps[variant])):
   dx=registration['variants'][variant]['frames'][n].get('dx')or 0
   new,mask=recolor(frame,glyphs,dx,variant,core);verify_pixels(frame,new,mask);new_frames.append(new);masks.append(mask)
  buffer=io.BytesIO();new_frames[0].save(buffer,format='PNG',optimize=True)
  stem='zug-dampf-outlook-brand-v1-'+variant;output[stem+'.png']=buffer.getvalue()
  gif_bytes,palette_count=encoded_gif(new_frames,masks,durations,disposal,loop,old_palette);output[stem+'.gif']=gif_bytes
  with Image.open(io.BytesIO(gif_bytes))as gif:
   if gif.n_frames!=36 or gif.info.get('loop')!=loop:raise ValueError('Encoded frame count or loop changed')
   for n,old in enumerate(frames):
    gif.seek(n)
    if gif.info.get('duration')!=durations[n]or gif.disposal_method!=disposal[n]:raise ValueError('Encoded timing or disposal changed')
    verify_pixels(old,gif.convert('RGBA'),masks[n])
  if new_frames[0].getchannel('A').getbbox()[0]!=0:raise ValueError('Final train left edge changed')
  stats[variant]={'frames':36,'duration_ms':sum(durations),'outside_badge_pixel_changes':0,'alpha_changes':0,'left_visible_x':0,'palette_entries':palette_count}
 with Image.open(secure_path('public/mail-assets/'+CAPTION))as image:
  image=image.convert('RGBA')
  if image.size!=(552,102)or image.getchannel('A').crop((0,0,552,25)).getextrema()[1]!=0:raise ValueError('Caption crop would remove visible ink')
  cropped=image.crop((0,25,552,102));buffer=io.BytesIO();cropped.save(buffer,format='PNG',optimize=True)
  output['outlook-hotline-label-inktop-v1.png']=buffer.getvalue()
 for name,data in output.items():
  pin=OUTPUT_PINS[name]
  if len(data)!=pin['bytes']or hashlib.sha256(data).hexdigest()!=pin['sha256']:raise ValueError('Reconstruction differs: '+name)
 pending=[]
 # Validate every output before writing any new file. Existing media stay immutable.
 for folder in OUTPUT_DIRS:
  for name,data in output.items():
   path=secure_path(folder+'/'+name)
   if path.exists():
    if path.read_bytes()!=data:raise ValueError('Refusing different existing output: '+name)
   elif check:raise ValueError('Missing immutable output: '+name)
   else:pending.append((path,data))
 for path,data in pending:
  with path.open('xb')as target:target.write(data)
 if svg_path.read_bytes()!=svg_before or any(hashlib.sha256(Path(path).read_bytes()).hexdigest()!=sha for path,sha in before.items()):raise ValueError('Original input changed')
 return {'mode':'check'if check else'build','Pillow':PILLOW_VERSION,'CairoSVG':cairosvg.__version__,'original_inputs_unchanged':True,'media_files':len(output),'new_files_created':len(pending),'all144_frames_verified':True,'max_vector_color_sample_edge_distance_px':max_sampling_distance,'caption_removed_rows':25,'caption_visible_pixels_removed':0,'variants':stats,'outputs':OUTPUT_PINS}

def main():
 parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--check',action='store_true');args=parser.parse_args()
 try:print(json.dumps(build_assets(check=args.check),indent=2))
 except(OSError,ValueError,AssertionError)as error:print('Branded media verification failed: '+str(error),file=sys.stderr);return 1
 return 0

if __name__=='__main__':raise SystemExit(main())
