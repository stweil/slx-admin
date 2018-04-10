var $gridInner = $('#draw-element-area');
var $gridFrame = $('#drawpanel');
var $grid = $('#drawarea');

if (!roomplanner) var roomplanner = {

		getScaleFactor: function() {
			return this.settings.scale/100;
		},
		getCellPositionFromPixels: function(left,top) {
			var n = this.settings.scale / this.settings.cellsep;
			return [ parseInt(left) - ((parseInt(left)%n)+n)%n , parseInt(top) - ((parseInt(top)%n)+n)%n];
		},
		getCellPositionFromGrid: function(row,col) {
			var n = this.settings.scale / this.settings.cellsep;
			return [ parseInt(col*n), parseInt(row*n) ]
		},
		getGridFromPixels: function(left,top) {
			var n = this.settings.scale / this.settings.cellsep;
			return [Math.round(top/n), Math.round(left/n)];
		},
		settings: {
			cellsep: 4,
			cellsize: 25,
			scale: 100,
			room: {
				width: 33,
				height: 33
			}
		},
		selectFromServer: selectMachine,
		isElementResizable: function(el) {
			return (!$(el).attr('noresize') && $(el).attr('itemtype') != 'pc'); 
		},
		/**
		 * elements with attribute itemlook ending with east, south, west or north are rotatable.
		 * if the attribute does not match to one of these directions, the rotation handle will not be added
		 */
		initRotation: function(el) {
			if (!(new RegExp(".*(east|south|west|north)$").test($(el).attr('itemlook')))) {
				return;
			}

			var $e = $('<div class="pcHandle glyphicon glyphicon-repeat"></div>');
			$(el).append($e);
			$e.click(function () {
				var str = $(el).attr('itemlook');
				if (str.indexOf('-') > -1){
					var values =str.split('-');
					var name = values[0];
					var direction = values[1];

					var re = new RegExp("east|south|west|north");
					if (re.test(direction)) {
						var newdirection;
						switch(direction) {
						    case "east":
						    	newdirection = "south";
						        break;
						    case "south":
						    	newdirection = "west";
						        break;
						    case "west":
						    	newdirection = "north";
						        break;
						    case "north":
						    	newdirection = "east";
						        break;
						}
						var result = name + "-" + newdirection;
						$(el).attr('itemlook', result);
					}
				} 
			});
		},
		/**
		 * adds delete handle to the element. if the item is a pc, the user has to confirm his intention
		 */
		initDelete: function(el) {
			$(el).append('<div class="deleteHandle glyphicon glyphicon-remove-sign"></div>');
			$(el).find('.deleteHandle').click(function() {
				if ($(this).parent().attr('itemtype') === "pc") {
					var self = this;
					BootstrapDialog.confirm(__('are you sure'),function(result) {
						if (result) {
							if (onPcDelete) {
								onPcDelete($(self).parent().attr('muuid'));
							}
							$(self).parent().remove();
						}
					});
				} else {
					$(this).parent().remove();
				}
			});
		},
		initPcButtons: function(el) {
			if ($(el).attr('itemtype') !== 'pc') return;
			var $e;
			if (!PLANNER_READ_ONLY) {
				$e = $('<div class="pcHandle glyphicon glyphicon-blackboard"></div>');
				$(el).append($e);
				$e.click(function () {
					var wasTutor = ($(this).parent().attr('istutor') === 'true');
					$('[itemtype="pc"]').removeAttr('istutor');
					if (!wasTutor) {
						$(this).parent().attr('istutor', 'true');
					}
				});
			}
			if (CAN_OPEN_STATS) {
				$e = $('<div class="pcHandle glyphicon glyphicon-eye-open"></div>');
				$(el).append($e);
				$e.click(function () {
					var uuid = $(this).parent().attr('muuid');
					console.log('Click: ', uuid);
					var url = '?do=statistics&uuid=' + uuid;
					if (roomplanner.serialize() !== plannerLoadState) {
						window.open(url);
					} else {
						window.location.href = url;
					}
				});
			}
		},
		initTooltip: function(el) {
			if ($(el).attr('itemtype') === 'pc') {
				var tip = "<b>Rechnerdaten</b><br>";
				$(roomplanner.computerAttributes).each(function(i,key){
					tip += __(key)+": "+$(el).attr(key)+"<br>";
				});

				$(el).attr('title', tip).attr('data-toggle', 'tooltip');
				$(el).tooltip({html: true, container: 'body'});
			}
		},
		/**
		 * initializes draggable functionality. elements collide with elements of the same itemtype
		 */		
		initDraggable: function(el) {
			$(el).draggable();
			var options = {
					"containment" : "#draw-element-area",
					"helper" : false,
					"grid" : [(roomplanner.settings.scale / 4), (roomplanner.settings.scale / 4)],
					"stop": function(ev,ui) {
						
						if ($(this).attr('itemtype').indexOf('_drag') > -1) {
							var itemtype = $(this).attr('itemtype').replace('_drag','');
							$(this).attr('itemtype',itemtype);
						}
					},
					"preventCollision" : true,
					"restraint": "#draw-element-area",
					"obstacle" : '[itemtype="'+$(el).attr('itemtype')+'"]',
					"start": function(ev,ui) {
						if (roomplanner.isElementResizable(this)) {
							$(this).resizable("option","maxHeight",null);
							$(this).resizable("option","maxWidth",null);
						}
						
						var itemtype = $(this).attr('itemtype');
						$(this).attr('itemtype',itemtype+'_drag');
						
					}
			};
			
			for (var o in options) {
				$(el).draggable("option",o,options[o]);
			}
		},
		/**
		 * initializes resizable functionality. elements collide with elements of the same itemtype
		 */	
		initResizable: function(el) {
			if (!roomplanner.isElementResizable(el)) { return; }
						
			$(el).resizable({
				containment : "#draw-element-area",
				obstacle: '[itemtype="'+$(el).attr('itemtype')+'"]',
				handles: "se",
				autoHide: true,
				grid: [(roomplanner.settings.scale / 4), (roomplanner.settings.scale / 4)],
				resize: function(ev,ui) {
					var gridSteps = $(this).resizable("option","grid");
								
					
					var collides = $(this).collision('[itemtype="'+$(el).attr('itemtype').replace('_drag','')+'"]');
					
					
					
					var pos = $(this).offset();
					var self = this;
					
					var mw = $(this).resizable("option","maxWidth");
					var mh = $(this).resizable("option","maxHeight");
					
					var hLimit = ($(this).attr('scalable') === 'v');
					var vLimit = ($(this).attr('scalable') === 'h');
					
					if(collides.length) {
						$(collides).each(function(idx,item) {
							var itempos = $(item).offset();
							
							if (!hLimit) {
								if (pos.left < itempos.left && (pos.left + $(self).width()) > itempos.left) {
									$(self).resizable("option","maxWidth",parseInt(itempos.left - pos.left));

								} else {
									$(self).resizable("option","maxWidth",null);
								}
							}
							
							if (!vLimit) {
								if (pos.top < itempos.top && pos.top + $(self).height() > itempos.top) {
									$(self).resizable("option","maxHeight",parseInt(itempos.top - pos.top));
								} else {
									$(self).resizable("option","maxHeight",null);
								}
							}
						});
					} else {
						if (!hLimit && (mw == null || mw > $(this).width())) {
							$(this).resizable("option","maxWidth",null);
						}						
						if (!vLimit && (mh == null || mh > $(this).height())) {
							$(this).resizable("option","maxHeight",null);
						}
					}
				},
				start: function(ev,ui) {
					
					var itemtype = $(this).attr('itemtype');
					$(this).attr('itemtype',itemtype+'_drag');
					
					$(this).css('opacity',0.8);
					
					var gridSteps = $(this).resizable("option","grid");
					
					$(this).resizable("option",{
						minHeight: gridSteps[1]*roomplanner.getScaleFactor(),
						minWidth: gridSteps[0]*roomplanner.getScaleFactor()
					});
					
					if ($(this).attr('scalable')) {
						switch ($(this).attr('scalable')) {
						case 'h':
							$(this).resizable("option",{
								minHeight: $(this).height(),
								maxHeight: $(this).height()
							});
							break;
						case 'v':
							$(this).resizable("option",{
								minWidth: $(this).width(),
								maxWidth: $(this).width()
							});
							break;
						}
					}
					
				},
				stop: function(ev,ui) {
										
					if ($(this).attr('itemtype').indexOf('_drag') > -1) {
						var itemtype = $(this).attr('itemtype').replace('_drag','');
						$(this).attr('itemtype',itemtype);
					}
					
					var gridSteps = $(this).resizable("option","grid");
					var mw = $(this).resizable("option","maxWidth");
					if (mw) {
						$(this).width(mw);
					} else {
						$(this).width($(this).outerWidth() - $(this).outerWidth()%(gridSteps[0]));
					}
					
					var mh = $(this).resizable("option","maxHeight");
					if (mh) {
						$(this).height(mh);
					} else {
						$(this).height($(this).outerHeight() - $(this).outerHeight()%(gridSteps[1]));
					}

					
					$(this).attr('data-width', $(this).outerWidth()/roomplanner.getScaleFactor() - (($(this).outerWidth()%gridSteps[0])/roomplanner.getScaleFactor()));
					$(this).attr('data-height', $(this).outerHeight()/roomplanner.getScaleFactor() - (($(this).outerHeight()%gridSteps[1])/roomplanner.getScaleFactor()));
				
					
					$(this).css('opacity',1);
				}
			});
		},
		/**
		 * serializes the elements on the drawboard.
		 * furniture and computers are considered.
		 * @NOTICE: if more itemtypes are added, they need to be implemented here, too!
		 */
		serialize: function() {
			
			var objects = {
					"furniture": [],
					"computers": []
			};
			
			var furniture = $gridInner.find('div[itemtype="furniture"]');
			furniture.each(function(idx,el) {
				objects.furniture.push({
					"gridRow" : $(el).attr('gridRow'),
					"gridCol" : $(el).attr('gridCol'),
					"data-width": $(el).attr('data-width'),
					"data-height": $(el).attr('data-height'),
					"itemlook": $(el).attr('itemlook'),
				});
			});
			
			var computers = $gridInner.find('div[itemtype="pc"]');
			computers.each(function(idx,el) {
				
				var object = {					
						"gridRow" : $(el).attr('gridRow'),
						"gridCol" : $(el).attr('gridCol'),
						"data-width": $(el).attr('data-width'),
						"data-height": $(el).attr('data-height'),
						"itemlook": $(el).attr('itemlook'),
						"muuid": $(el).attr('muuid')
					};
												
				objects.computers.push(object)
			});
			
			
			return JSON.stringify(objects);
		},
		/**
		 * tries to reconstruct a serialized room.
		 * furniture and computers are considered
		 * @NOTICE: if new itemtypes are added to the application, they need to be implemented here, too!
		 */
		load: function(object) {
			if (typeof object === 'string') {
				try {
					var objects = JSON.parse(object);
				} catch (e) {
					alert('invalid JSON format');
					return false;
				}
			} else {
				var objects = object;
			}
			
			$gridInner.html('');
			
			function itemToHtml(item, itemtype, obstacle) {
				var html = '<div itemtype="'+itemtype+'" style="position:absolute;"  ';
				for (var prop in item) {
					if (!item.hasOwnProperty(prop)) continue;
					html += prop+'="'+item[prop]+'" ';
				}
				html += 'class="ui-draggable';
				if (!PLANNER_READ_ONLY) {
					html += ' draggable';
				}
				html += '"></div>';
				return html;
			}
			
			if (objects.furniture) {
				var furniture = objects.furniture;
				for (var piece in furniture) {
					var item = itemToHtml(furniture[piece], "furniture", true);
					$gridInner.append(item);
					
				}
			}
			
			
			if (objects.computers) {
				var computers = objects.computers;
				for (var piece in computers) {
					var item = itemToHtml(computers[piece], "pc", false);
					$gridInner.append(item);
				}
			}
			
			$gridInner.find('.draggable').each(function(idx,el) {
				roomplanner.initDraggable(el);
				roomplanner.initResizable(el);
				roomplanner.initTooltip(el);
				roomplanner.initRotation(el);
				roomplanner.initDelete(el);
			});
			$gridInner.find('.ui-draggable').each(function(idx,el) {
				roomplanner.initPcButtons(el);
			});
			
			roomplanner.grid.scale(roomplanner.settings.scale);
			roomplanner.fitContent();
		},
		clear: function() {
			$gridInner.html('');
		}
};

roomplanner.grid = (function() {
		var grid = {
			resize: function() {
				var w = Math.max($gridFrame.find('.panel-body').width(),roomplanner.settings.room.width*roomplanner.settings.scale)
				var h = Math.max($gridFrame.find('.panel-body').height(),roomplanner.settings.room.height*roomplanner.settings.scale)
				$grid.width(w);
				$grid.height(h);
			},
			scale: function(num) {
				
				var area_left = parseInt($grid.css('left')) - $gridFrame.find('.panel-body').width()/2 ;
				var area_top = parseInt($grid.css('top')) - $gridFrame.find('.panel-body').height()/2;
				
				var opts = {
						left: ((parseInt(area_left) * num / roomplanner.settings.scale ) + $gridFrame.find('.panel-body').width()/2)+ "px" ,
						top: ((parseInt(area_top)  * num / roomplanner.settings.scale ) + $gridFrame.find('.panel-body').height()/2)+ "px"
					};
				
				$grid.css(opts);
				
				
				$grid.css('background-size',num);
				roomplanner.settings.scale = num;
				$gridInner.find('.ui-draggable').each(function(idx,item) {
					var $item = $(item);
					var h = $item.attr('data-height') * roomplanner.getScaleFactor();
					var w = $item.attr('data-width') * roomplanner.getScaleFactor();
					//var pos = roomplanner.getCelloffset()

					var pos = roomplanner.getCellPositionFromGrid($item.attr('gridRow'),$item.attr('gridCol'));
					
					$item.css({width: w+"px", height: h+"px", left: pos[0]+"px", top: pos[1]+"px"});
					if ($item.hasClass('draggable')) {
						$item.draggable("option", "grid", [(roomplanner.settings.scale / 4), (roomplanner.settings.scale / 4)]);
						if (roomplanner.isElementResizable(item)) {
							$item.resizable("option", "grid", [(roomplanner.settings.scale / 4), (roomplanner.settings.scale / 4)]);
						}
					}
				});
				this.resize();
			},
			init: function() {
				this.resize();
				$(window).resize($.proxy(function(){
					this.resize();
				},this));
			}
		}
		
		return grid;
}
)();

roomplanner.fitContent = function() {
	var minX = 99999;
	var minY = 99999;
	var maxX = -99999;
	var maxY = -99999;
	$gridInner.find('.ui-draggable').each(function(idx,item) {
		var $item = $(item);

		var l = parseInt($item.attr('gridcol')) * roomplanner.settings.cellsize;
		var r = l + parseInt($item.attr('data-width'));
		var t = parseInt($item.attr('gridrow')) * roomplanner.settings.cellsize;
		var b = t + parseInt($item.attr('data-height'));

		if (l < minX) minX = l;
		if (t < minY) minY = t;
		if (r > maxX) maxX = r;
		if (b > maxY) maxY = b;
	});
	if (minX > maxX)
		return;
	var width = (maxX - minX) / $gridFrame.find('.panel-body').width();
	var height = (maxY - minY) / $gridFrame.find('.panel-body').height();
	var scale;
	if (width > height) {
		scale = Math.floor(100 / width);
	} else {
		scale = Math.floor(100 / height);
	}
	roomplanner.slider.slider('value', scale);
	scale = roomplanner.settings.scale;
	var opts = {
		left: -(minX * (scale / 100)) + "px",
		top: -(minY * (scale / 100)) + "px"
	};

	$grid.css(opts);
};

$(document).ready(function(){
	roomplanner.grid.init();

	var update = function(event,ui) {
		roomplanner.grid.scale(ui.value);
	};
	
	roomplanner.slider = $('#scaleslider').slider({
		orientation: "horizontal",
		range: "min",
		min: 40,
		max: 150,
		value: 100,
		change: update,
		slide: update,
		stop: function(e, ui) {
			$grid.trigger('checkposition');
		}
	
	});
	
	$grid.bind('checkposition', function() {
		if ($(this).offset().left > 0) {
			$(this).css('left',0);
		}
		if (parseInt($(this).css('top')) > 0) {
			$(this).css('top',0);
		}
		
		if (($(this).width() + parseInt($(this).css('left'))) < $(this).parent().width()) {
			$(this).css('left', ($(this).parent().width() - $(this).width()));
		}
		
		if (($(this).height() + parseInt($(this).css('top'))) < $(this).parent().height()) {
			$(this).css('top', ($(this).parent().height() - $(this).height()));
		}
	});
	
	$grid.draggable({
		stop: function() {
			$(this).trigger('checkposition');
		}
	});
	
	/**
	 * adds droppable functionality to the draw area for the elements.
	 * drop event is only fired for elements added to the board from the toolbar.
	 */
	$gridInner.droppable({
		accept: ".draggable",
		drop: function(event, ui) {
			
			// the element is already in drawing area
			var el = (ui.helper == ui.draggable) ? ui.draggable : $(ui.helper.clone());
			
			var collidingElements = $(el).collision('[itemtype="'+$(el).attr('itemtype').replace('_drag','')+'"]');
			
			var i = 0;
			while (collidingElements.length > 0) {
				// too much tries - abort
				if (i > 5) { return; }
				
				
				if (ui.helper != ui.draggable) {
					var leftPos = parseInt($(el).css('left'))-parseInt($grid.css('left'))-$gridFrame.offset().left;
					var topPos = parseInt($(el).css('top'))-parseInt($grid.css('top'))-($gridFrame.offset().top + $gridFrame.find('.panel-heading').height());
					var cp = roomplanner.getCellPositionFromPixels(leftPos,topPos);
					leftPos = cp[0];
					topPos = cp[1];
				
				} else {
					var leftPos = parseInt($(el).css('left'));
					var topPos = parseInt($(el).css('top'));
				}
				
				var collider = $(collidingElements[0]);
				var colliderTop = parseInt(collider.css('top'));
				var colliderLeft = parseInt(collider.css('left'));
				
				var overlap = {
						x: Math.min(colliderLeft+collider.outerWidth(),leftPos+$(el).outerWidth()) - Math.max(leftPos,colliderLeft),
						y: Math.min(colliderTop+collider.outerHeight(),topPos+$(el).outerHeight()) - Math.max(topPos,colliderTop)
				};
				
				if (overlap.x <= overlap.y) {
					var lpos = parseInt($(el).css('left'));
					if (colliderLeft + overlap.x == leftPos + $(el).width()) {
						$(el).css('left',(lpos - (overlap.x+2))+"px");
					} else {
						$(el).css('left',(lpos + overlap.x+2)+"px");
					}
				} else {
					var tpos = parseInt($(el).css('top'));
					if (colliderTop + overlap.y == topPos + $(el).height()) {
						$(el).css('top',(tpos - (overlap.y+2))+"px");
					} else {
						$(el).css('top',(tpos + overlap.y+2)+"px");
					}
				}
				collidingElements = $(el).collision('[itemtype="'+$(el).attr('itemtype').replace('_drag','')+'"]');
					
				i++;
			}
			
			var itemtype = $(el).attr('itemtype');
			$(el).attr('itemtype',itemtype.replace('_drag',''));
			
			$(el).removeClass('collides');
			$(el).css('opacity',1);
			
			if (ui.helper != ui.draggable) {
				var l = parseInt($(el).css('left'))-parseInt($grid.css('left'))-$gridFrame.offset().left;
				var t = parseInt($(el).css('top'))-parseInt($grid.css('top'))-($gridFrame.offset().top + $gridFrame.find('.panel-heading').height());
				var cp = roomplanner.getCellPositionFromPixels(l,t);
				$(el).css('left',cp[0]);
				$(el).css('top',cp[1]);
			}
			
			var gridPositions = roomplanner.getGridFromPixels(parseInt($(el).css('left')),parseInt($(el).css('top')));
			$(el).attr('gridRow',gridPositions[0]);
			$(el).attr('gridCol',gridPositions[1]);
						
			
			roomplanner.initResizable(el);
			roomplanner.initDraggable(el);
			
			if (ui.helper != ui.draggable) {
				$(this).append(el);
				
				if ($(el).attr('itemtype') == "pc") {
					
					var uuids = [];
					var computers = $gridInner.find('div[itemtype="pc"]');
					computers.each(function(idx,el) {
						if ($(el).attr('muuid')) {
							uuids.push($(el).attr('muuid'));
						}
					});
					
					roomplanner.selectFromServer(uuids, function (result) {
						if (!result) {
							$(el).remove();
						} else {
							for (var key in result) {
								$(el).attr(key,result[key]);
							}
							roomplanner.initTooltip(el);
						}
					});
				}
				roomplanner.initRotation(el);
				roomplanner.initDelete(el);
				roomplanner.initPcButtons(el);
			}
			
		}
	});
	/**
	 * adds draggable functionality to all elements from the toolbar
	 */
	$('.draggable').draggable({
		helper: "clone",
		//grid : [(roomplanner.settings.scale / 4), (roomplanner.settings.scale / 4)],
		preventCollision: true,
		restraint: "#draw-element-area",
		cursorAt: {left:5,top:5},
		start: function(ev,ui) {
				$(ui.helper).css('opacity',0.8);
				$(ui.helper).height($(this).attr('data-height')*roomplanner.getScaleFactor());
				$(ui.helper).width($(this).attr('data-width')*roomplanner.getScaleFactor());
				var type = $(ui.helper).attr('itemtype');
				$(ui.helper).attr('itemtype',type+"_drag");
		},
		drag: function(ev,ui) { 
			if ($(ui.helper).collision('[itemtype="'+$(ui.helper).attr('itemtype').replace('_drag','')+'"]').length) {
				$(ui.helper).addClass('collides');
			} else {
				$(ui.helper).removeClass('collides');
			}
		}
	})
	
});
